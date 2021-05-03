"""
Reconcile expected indices against live cluster state

Reconciles the state of multiple elasticsearch clusters against the expected
state of multiple wikis. The reconciliation process is accept based. Some
number of acceptors can be configured per-cluster. Each implementation receives
information about the state of a cluster and is expected to yield the set of
indices it expects to exist on that cluster. The output is then the set of
indices that exist but should not, and the set of indices that should exist but
do not. Some attempts are made to explain why a specific index should not
exist.
"""
from argparse import ArgumentParser
from collections import Counter, defaultdict
from enum import Enum
import itertools
import json
import logging
import pickle
import re
import requests
import subprocess
import sys
from typing import Mapping, NamedTuple, Optional, Sequence, Set, Tuple


log = logging.getLogger(__name__)


def arg_parser():
    parser = ArgumentParser()
    parser.add_argument('--run-cache-path')
    return parser


# Configuration of the checker for one elasticsearch cluster.
ClusterToCheck = NamedTuple('ClusterToCheck', [
    ('cluster_name', str), ('replica', str), ('group', str),
    ('base_url', str), ('accept', Sequence)])
ClusterToCheck.key = property(lambda self: (self.replica, self.group))

# State of a single elasticsearch cluster
ElasticsearchState = NamedTuple('ElasticsearchState', [
    ('cluster_name', str), ('replica', str), ('group', str),
    ('indices', Set[str]), ('aliases', Mapping[str, str])])
ElasticsearchState.key = property(lambda self: (self.replica, self.group))

# Expected state of a cluster for a single wiki
CirrusCluster = NamedTuple('CirrusCluster', [
    ('replica', str), ('group', str), ('aliases', Sequence[str])])
CirrusCluster.key = property(lambda self: (self.replica, self.group))

# Expected state of a single wiki
WikiState = NamedTuple('WikiState', [
    ('dbname', str), ('clusters', Sequence[CirrusCluster])])


class ProblemKind(Enum):
    MISSING = 1  # Should exist but does not
    EXTRA = 2  # Should not exist but does
    OTHER = 3  # bad configuration?


# An index that doesn't match the expected state
Problem = NamedTuple('Problem', [
    ('cluster', str), ('index', str), ('kind', ProblemKind), ('reason', str)])


def make_elasticsearch_state(config: ClusterToCheck):
    base_url = config.base_url
    res = requests.get(base_url)
    res.raise_for_status()
    health = res.json()
    if health['cluster_name'] != config.cluster_name:
        raise Exception('Cluster at {} expected to be {} but found {}'.format(
            base_url, config.cluster_name, health['cluster_name']))

    res = requests.get(base_url + '/_cat/indices', headers={
        'Accept': 'application/json',
    })
    res.raise_for_status()
    indices = {index['index'] for index in res.json()
               if index['status'] == 'open'}

    res = requests.get(base_url + '/_cat/aliases', headers={
        'Accept': 'application/json',
    })
    res.raise_for_status()
    aliases = {alias['alias']: alias['index'] for alias in res.json()}

    return ElasticsearchState(
        health['cluster_name'], config.replica, config.group, indices, aliases)


def all_dbnames():
    output = subprocess.check_output(['expanddblist', 'all'])
    return output.decode('utf8').strip().split('\n')


def make_wiki_state(dbname):
    raw_text = subprocess.check_output([
        'mwscript',
        'extensions/CirrusSearch/maintenance/ExpectedIndices.php',
        '--wiki', dbname, '--oneline'
    ])
    raw = json.loads(raw_text.decode('utf8'))
    return WikiState(raw['dbname'], [
        CirrusCluster(replica, state['group'], state['aliases'])
        for replica, state in raw['clusters'].items()
    ])


def validate_cluster(
    config: ClusterToCheck,
    cluster_state: ElasticsearchState,
) -> Sequence[Problem]:
    accepted = [
        set(index_acceptor.accept(cluster_state))
        for index_acceptor in config.accept
    ]
    unique = set().union(*accepted)

    if len(unique) != sum(len(x) for x in accepted):
        yield from report_duplicate_accepted(config, accepted)

    extra = cluster_state.indices.difference(unique)
    for index in extra:
        yield Problem(config, index, ProblemKind.EXTRA,
                      'Index not expected on cluster')
    missing = unique.difference(cluster_state.indices)
    for index in missing:
        yield Problem(config, index, ProblemKind.MISSING,
                      'Expected index was missing')


def try_to_explain(
    problem: Problem,
    es_state: Mapping[Tuple[str, str], ElasticsearchState],
) -> Optional[str]:
    for index_acceptor in problem.cluster.accept:
        if not hasattr(index_acceptor, 'explain'):
            continue
        explain = index_acceptor.explain(problem, es_state)
        if explain:
            return explain
    return None


def validate_clusters(clusters: Sequence[ClusterToCheck]) -> Sequence[Problem]:
    clusters = list(clusters)
    es_states = {
        cluster.key: make_elasticsearch_state(cluster)
        for cluster in clusters}
    for cluster in clusters:
        for problem in validate_cluster(cluster, es_states[cluster.key]):
            explain = try_to_explain(problem, es_states)
            if explain:
                yield Problem(
                    problem.cluster, problem.index, problem.kind, explain)
            else:
                yield problem


def report_duplicate_accepted(
    config: ClusterToCheck,
    accepted: Sequence[Sequence[str]]
) -> Sequence[Problem]:
    counts = Counter(item for sublist in accepted for item in sublist)
    for index, repeats in counts.items():
        if repeats > 1:
            msg = 'Accepted by {} different acceptors'.format(repeats)
            yield Problem(config, index, ProblemKind.OTHER, msg)


def marker_ts(marker: str) -> int:
    # Converts the final portion of a cirrussearch index name,
    # the 12345 from devwiki_content_12345, into it's integer
    # complement.
    if marker == 'first':
        return 0
    return int(marker)


class CirrusExpectedIndicesGenerator:
    @classmethod
    def from_wiki_states(cls, wiki_states: Sequence[WikiState]):
        clusters = defaultdict(list)
        for wiki_state in wiki_states:
            for cirrus_cluster in wiki_state.clusters:
                clusters[cirrus_cluster.key] += cirrus_cluster.aliases
        return cls(clusters)

    def __init__(self, clusters: Mapping[Tuple[str, str], Sequence[str]]):
        self.clusters = clusters

    def accept(self, cluster_state: ElasticsearchState) -> Sequence[str]:
        # metastore should exist on all cirrussearch clusters
        try:
            yield cluster_state.aliases['mw_cirrus_metastore']
        except KeyError:
            yield 'mw_cirrus_metastore'

        key = (cluster_state.replica, cluster_state.group)
        is_cloud = cluster_state.cluster_name.startswith('cloudelastic-')
        for alias in self.clusters[key]:
            try:
                yield cluster_state.aliases[alias]
            except KeyError:
                is_titlesuggest = alias.endswith('_titlesuggest')
                # hax: cloudelastic could have titlesuggest, but we don't build
                # those. Ignore the problem for now.
                if not (is_cloud and is_titlesuggest):
                    yield alias

    INDEX_PAT = re.compile(r'^(\w+)_(\w+)_(first|\d+)$')

    def explain(
        self,
        problem: Problem,
        es_state: Mapping[Tuple[str, str], ElasticsearchState],
    ) -> Optional[str]:
        """Explain why this index wasn't accepted

        Only responds to extra indices that were not accepted but
        plausibly could have been.
        """
        if problem.kind != ProblemKind.EXTRA:
            return None
        match = self.INDEX_PAT.search(problem.index)
        if not match:
            return None
        wiki, index_type, marker = match.groups()
        expected_alias = '{}_{}'.format(wiki, index_type)
        if expected_alias in self.clusters[problem.cluster.key]:
            # This type of index should exist, is there a different live index?
            cluster_state = es_state[problem.cluster.key]
            try:
                live_index = cluster_state.aliases[expected_alias]
            except KeyError:
                return 'Index alias for {} expected but does not exist'.format(
                    expected_alias)
            # Make a guess based on timestamps, if the index is older than
            # the live index it's very likely dead. If newer it could be
            # a live reindex or a failed reindex. We could try poking
            # _tasks api to find live reindexes?
            live_match = self.INDEX_PAT.search(live_index)
            if not live_match:
                return (
                    'Duplicate of live index {}.'
                    'Live index doesnt have valid name format?'
                ).format(live_index)
            live_marker = live_match.group()[-1]
            if live_marker == 'first':
                live_marker = 0
            try:
                if marker_ts(live_marker) > marker_ts(marker):
                    reason = 'Reindex in progress?'
                else:
                    reason = 'Failed reindex?'
            except TypeError:
                reason = 'One of the index naming formats is unrecognized'
            return 'Duplicate of live index ' + live_index + '. ' + reason

        # This kind of index was not expected here, is it supposed to be on a
        # a different cluster in the same replica?
        expected_clusters = [
            key for key, aliases in self.clusters.items()
            if key[0] == problem.cluster.replica and expected_alias in aliases
        ]
        if expected_clusters:
            return "Index on wrong cluster of replica, expected in " + ', '.join(
                    es_state[key].cluster_name for key in expected_clusters)
        if any(expected_alias in aliases for aliases in self.clusters.values()):
            return (
                "Index not expected in this group."
                "Private index on non-private cluster?"
            )
        return (
            "Looks like Cirrus, but did not expect to exist."
            "Deleted wiki?"
        )


class IndexPatternAcceptor:
    """Accept indices by regex match against index name"""
    def __init__(self, re_pattern):
        self.re_pattern = re_pattern

    def accept(self, cluster_state: ElasticsearchState) -> Sequence[str]:
        for index_name in cluster_state.indices:
            if self.re_pattern.search(index_name):
                yield index_name


class GlentIndexAcceptor:
    """Accept glent production indices

    At any given time glent should have two live indices, sometimes a
    third if an import is currently running. The two allowed live indices
    are identified via the index aliases. A third index is only accepted
    if it has a date newer than the other live indices.
    """

    def accept(self, cluster_state: ElasticsearchState) -> Sequence[str]:
        # Expect two hardcoded alias names and accept the concrete
        # indices they point to.
        expected_aliases = {'glent_production', 'glent_rollback'}
        found = {
            cluster_state.aliases[alias]
            for alias in expected_aliases
            if alias in cluster_state.aliases
        }
        yield from found


def make_all_wiki_state() -> Sequence[WikiState]:
    return [make_wiki_state(dbname) for dbname in all_dbnames()]


def compute_if_absent(fn, path):
    try:
        with open(path, 'rb') as f:
            return pickle.load(f)
    except FileNotFoundError:
        pass

    result = fn()
    with open(path, 'wb') as f:
        pickle.dump(result, f)
    return result


def build_config(run_cache_path: Optional[str] = None) -> Sequence[ClusterToCheck]:
    if run_cache_path is None:
        wiki_state = make_all_wiki_state()
    else:
        # Collecting wiki state can take a few minutes for ~900 wikis.
        # A common use case will be to run script, change same state in
        # elasticsearch without changing cirrus config, and then rerun. By
        # caching we make the script a bit more interactive.
        # It would perhaps be more elegant to cache the individual json inputs,
        # but this is simple and works acceptably for purpose.
        wiki_state = compute_if_absent(make_all_wiki_state, run_cache_path)

    accept_all = [
        CirrusExpectedIndicesGenerator.from_wiki_states(wiki_state),
        # internal elasticsearch index
        IndexPatternAcceptor(re.compile(r'^\.tasks$')),
        # ltr plugin for elasticsearch
        IndexPatternAcceptor(re.compile(r'^\.ltrstore$')),
    ]
    # Indices that are not per-wiki are only found in chi
    accept_by_group = {
        'chi': [
            GlentIndexAcceptor(),
            IndexPatternAcceptor(re.compile(
                r'^apifeatureusage-\d\d\d\d\.\d\d\.\d\d$')),
            IndexPatternAcceptor(re.compile(r'^ttmserver(-test)?$')),
            IndexPatternAcceptor(re.compile(r'^phabricator$')),
        ]
    }

    ports = {'chi': 9243, 'omega': 9443, 'psi': 9643}
    for replica in ('eqiad', 'codfw'):
        for group, port in ports.items():
            accept = accept_all + accept_by_group.get(group, [])
            name = 'production-search-{}-{}'.format(group, replica)
            if group == 'chi':
                # chi was at one time the only group and still has
                # the old name not including the group.
                name = 'production-search-{}'.format(replica)
            yield ClusterToCheck(
                cluster_name=name,
                replica=replica,
                group=group,
                base_url='https://search.svc.{}.wmnet:{}'.format(
                    replica, ports[group]),
                accept=accept)

    for group, port in ports.items():
        yield ClusterToCheck(
            cluster_name='cloudelastic-{}-eqiad'.format(group),
            replica='cloudelastic',
            group=group,
            base_url='https://cloudelastic.wikimedia.org:' + str(port),
            # cloudelastic contains only cirrus indices, nothing secondary
            accept=accept_all,
        )


def sort_and_group(iterable, key):
    return itertools.groupby(sorted(iterable, key=key), key=key)


def report_problems(problems: Sequence[Problem]) -> Sequence[Mapping]:
    grouped = sort_and_group(problems, key=lambda p: p.cluster)
    return [{
        'cluster_name': cluster.cluster_name,
        'url': cluster.base_url,
        'problems': [{
            'index': p.index,
            'reason': p.reason,
        } for p in problems]
    } for cluster, problems in grouped]


def main(run_cache_path: Optional[str]) -> int:
    clusters_to_check = build_config(run_cache_path)
    problems = validate_clusters(clusters_to_check)
    print(json.dumps(report_problems(problems)))
    return 0


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    sys.exit(main(**dict(vars(arg_parser().parse_args()))))
