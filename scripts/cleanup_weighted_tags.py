# Provides a mechanism to clear weighted_tags groups from
# all live cirrussearch indices.
#
# Currently this requires providing a list of full indexed values
# (such as recomendation.image/exists) to ensure complete coverage.
#
# To support partial clears a separate csv must be provided containing a list
# of page id's to exclude from the update. The csv must be headerless and
# contain exactly two fields: first the wiki dbname and second the page_id.
#
# The implementation loops over appropriate wikis, sources their active
# clusters from a Cirrus maintenance script, and then scrolls over all
# documents for the wiki to find those matching the provided indexed
# values. super_detect_noop updates are issued against matching documents
# to delete all referenced prefixes.
#
# NOTE: All referenced prefixes are deleted from all matching documents.
# If clearing multiple prefixes you may want multiple executions.
#

from __future__ import annotations
import argparse
import csv
from collections import defaultdict
from dataclasses import dataclass
import os
import json
import logging
import subprocess
from typing import Mapping, Sequence, Set, TextIO

from elasticsearch import Elasticsearch
from elasticsearch.helpers import streaming_bulk, scan


WEIGHTED_TAGS = 'weighted_tags'


def arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        '--allow-updates', action='store_true', default=False,
        help='Updates are only issued when provided, otherwise a dry run is performed.')
    parser.add_argument(
        '--page-id-exclusions', dest='page_id_exclusions_file', required=True,
        type=argparse.FileType(mode='rt', encoding='utf8'),
        help='Headerless CSV containing (wiki, page_id) pairs to exclude from updates. This also decides the set of wikis to operate on.')
    parser.add_argument(
        '--verbose', action='store_true', default=False,
        help='Print updates that will be applied')
    parser.add_argument(
        '--weighted-tags-queries', nargs='+', required=True,
        help='Indexed weighted_tags values to look for (ex: recommendation.image/exists)')
    return parser


def load_exclusions(io: TextIO) -> Mapping[str, Set[int]]:
    """Load pages to exclude from update

    path must contain a headerless csv with wiki dbname as first
    column and page_id to exclude from updates as the second column.
    """
    exclusions = defaultdict(set)
    with io:
        for wiki, page_id in csv.reader(io):
            exclusions[wiki].add(int(page_id))
    return exclusions


def weighted_tags_query(queries: Sequence[str]) -> dict:
    return {
        '_source': False,
        'sort': ['_doc'],
        'query': {
            'bool': {
                'filter': [{
                    'match': {
                        WEIGHTED_TAGS: {
                            'query': query,
                        }
                    }
                } for query in queries]
            }
        }
    }


def build_update_params(queries: Sequence[str]) -> Mapping:
    """super_detect_noop params to clear referenced weighted_tags prefixes"""
    prefixes = set(q.split('/')[0] for q in queries)
    return {
        'handlers': {
            WEIGHTED_TAGS: 'multilist',
        },
        'source': {
            WEIGHTED_TAGS: [prefix + '/__DELETE_GROUPING__' for prefix in prefixes]
        }
    }


def build_update_action(hit: Hit, params: Mapping) -> Mapping:
    return {
        '_op_type': 'update',
        '_index': hit.index,
        '_type': 'page',
        '_id': hit.page_id,
        'script': {
            'source': 'super_detect_noop',
            'lang': 'super_detect_noop',
            'params': params,
        }
    }


@dataclass
class Wiki:
    dbname: str
    clusters: Sequence[Mapping]

    @classmethod
    def from_mwscript(cls, wiki: str) -> Wiki:
        raw = json.loads(subprocess.check_output([
            'mwscript', 'extensions/CirrusSearch/maintenance/ExpectedIndices.php',
            '--wiki', wiki
        ]))
        return cls(
            dbname=wiki,
            clusters=[config['connection'] for config in raw['clusters'].values()])

    @property
    def clients(self) -> Sequence[Elasticsearch]:
        # Might not work with all ways of configuring cirrussearch clusters, but works with the expanded
        # definition of [{host: ..., port: ..., transport: ...}, ...]
        return [
            Elasticsearch(conn_info, ca_certs=os.environ.get('REQUESTS_CA_BUNDLE'))
            for conn_info in self.clusters
        ]

    @property
    def all_index(self) -> str:
        """Index name to search against for all pages of wiki"""
        return self.dbname


@dataclass
class Hit:
    index: str
    page_id: int


class ProgressReporter:
    def __init__(self, freq=100, line_length=80):
        self.seen = 0
        self.ok = True
        self.freq = freq
        self.line_length = line_length
        self.cr_freq = freq * line_length

    def __call__(self, ok, action):
        self.seen += 1
        self.ok &= ok
        if self.seen % self.freq == self.freq - 1:
            progress = '.' if self.ok else 'E'
            cr = self.seen % self.cr_freq == self.cr_freq - 1
            print(progress, flush=True, end='\n' if cr else '')
            self.ok = True


class VerboseReporter:
    def __call__(self, ok, action):
        print(action)


def main(
    allow_updates: bool,
    page_id_exclusions_file: TextIO,
    verbose: bool,
    weighted_tags_queries: Sequence[str],
):
    page_id_exclusions = load_exclusions(page_id_exclusions_file)
    reporter = VerboseReporter() if verbose else ProgressReporter()
    super_detect_noop_params = build_update_params(weighted_tags_queries)
    search_query = weighted_tags_query(weighted_tags_queries)
    # pre-populate to validate all wiki names
    wikis = [Wiki.from_mwscript(dbname) for dbname in page_id_exclusions.keys()]

    for wiki in wikis:
        logging.info('Starting against wiki: ' + wiki.dbname)
        excluded_page_ids = page_id_exclusions[wiki.dbname]
        for client in wiki.clients:
            logging.info('Starting against cluster: ' + client.cluster.health()['cluster_name'])
            raw_hits = scan(client, index=wiki.all_index, query=search_query)
            hits = (Hit(raw['_index'], int(raw['_id'])) for raw in raw_hits)
            actions = (
                build_update_action(hit, super_detect_noop_params)
                for hit in hits if hit.page_id not in excluded_page_ids)
            if allow_updates:
                results = streaming_bulk(client=client, actions=actions)
            else:
                results = ((True, action) for action in actions)

            for ok, action in results:
                reporter(ok, action)


if __name__ == "__main__":
    logging.basicConfig(level=logging.WARNING)
    main(**dict(vars(arg_parser().parse_args())))
