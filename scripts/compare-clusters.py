import getopt
import json
import math
import multiprocessing
import sys
import traceback
import requests
import urllib3

proxies = {} # {'https': 'socks5h://127.0.0.1:1337'} # when tunneling
verify = True # False # when tunneling and local OS does not have the certificates
#urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning) # when verify = False

def fetch_docs(session, index_uri, ids):
    url = f'{index_uri}/_doc/_mget?stored_fields=_id&_source_includes=version,title'
    payload = json.dumps({'ids': ids})

    r = session.get(url, headers={'Content-Type': 'application/json'}, data=payload, proxies=proxies, verify=verify)
    docs = json.loads(r.text)['docs']
    by_id = {}
    for doc in docs:
        source = doc['_source'] if '_source' in doc else {'version': -1, 'title': ''}
        by_id[doc['_id']] = {
            'found': doc['found'],
            'rev': source['version'],
            'title': source['title']
        }
    return by_id


def fetch_docs_with_retry(session, index_uri, ids):
    attempt = 0
    while attempt < 3:
        try:
            return fetch_docs(session, index_uri, ids)
        except Exception:
            attempt += 1
    raise Exception('Failed fetching docs…')


def compare(q, docs_by_cluster, stats, index_type):
    keys = list(docs_by_cluster.keys())
    head = keys[0]
    other = keys[1:]
    if not other:
        raise Exception('Cannot compare, only one cluster of docs provided')
    expected_len = len(docs_by_cluster[head])
    for cluster in other:
        if len(docs_by_cluster[cluster]) != expected_len:
            raise Exception(f'Counts dont match: {len(docs_by_cluster[cluster]):d} != {expected_len:d}')
    for page_id, head_doc in docs_by_cluster[head].items():
        stats[0] += 1
        error = False
        for cluster in other:
            if not error:
                if not head_doc['found'] == docs_by_cluster[cluster][page_id]['found']:
                    stats[1] += 1
                    error = True
                    q.put_nowait({'id': page_id, 'reason': 'not_found', 'expected': True, 'actual': False, 'index': index_type})
                elif not head_doc['rev'] == docs_by_cluster[cluster][page_id]['rev']:
                    stats[2] += 1
                    error = True
                    q.put_nowait({'id': page_id, 'reason': 'rev_missmatch', 'expected': head_doc['rev'],
                                  'actual': docs_by_cluster[cluster][page_id]['rev'], 'index': index_type})
                elif not head_doc['title'] == docs_by_cluster[cluster][page_id]['title']:
                    stats[3] += 1
                    error = True
                    q.put_nowait({'id': page_id, 'reason': 'title_missmatch', 'expected': head_doc['title'],
                                  'actual': docs_by_cluster[cluster][page_id]['title'], 'index': index_type})
            del docs_by_cluster[cluster][page_id]
    for cluster in other:
        if docs_by_cluster[cluster]:
            raise Exception(f'Doc(s) returned from {head} but not {cluster}: {",".join(docs_by_cluster[cluster])}')


def run(wiki, clusters, index_types, batch_size, start, end, q, stats):
    print(f'Comparing wiki {wiki} in {clusters} for index types {index_types}; processing page IDs [{start}, {end}]')
    session = requests.Session()
    for value in range(start, end, batch_size):
        ids = list(range(value, value + batch_size))
        for index_type in index_types:
            compare(q, {
                cluster: fetch_docs_with_retry(session, f'{clusters[cluster]}/{wiki}_{index_type}', ids)
                for cluster in clusters
            }, stats, index_type)


def listen(wiki, q):
    while True:
        try:
            error = q.get()
            if error is None:
                break
            print(f'wiki: {wiki} error: {error}')
        except (KeyboardInterrupt, SystemExit):
            raise
        except Exception:
            print('Whoops! Problem:', file=sys.stderr)
            traceback.print_exc(file=sys.stderr)


def fetch_max_id(session, index_uri):
    url = f'{index_uri}/_doc/_search'
    payload = json.dumps({'size': 0, 'aggs': {'max_page_id': {'max': {'field': 'page_id'}}}})

    r = session.get(url, headers={'Content-Type': 'application/json'}, data=payload, proxies=proxies, verify=verify)
    if r.status_code != 200:
        print(f'Looks like this index does not exist: POST {url} results in {r.status_code} {r.text}')
        return 0
    aggregations = json.loads(r.text)['aggregations']

    return int(float(aggregations['max_page_id']['value']))


def print_help(clusters, batch_size, index_types):
    print(f'Usage: {sys.argv[0]} <options> <wiki>\n'
          f'options:\n'
          f'-l --left\t\tleft cluster URI (value: {clusters["left"]})\n'
          f'-r --right\t\tright cluster URI (value: {clusters["right"]})\n'
          f'-b --batch-size\tbatch size (int, value: {batch_size})\n'
          f'-t --type\t index type to check (value: [{", ".join(index_types)}], cardinality: 1+)')


def main():
    stats = multiprocessing.Array('i', [0, 0, 0, 0])

    batch_size = 2000
    index_types = ['general', 'content', 'file']
    clear_index_types = True
    clusters = {
        "left": 'http://localhost:9200',
        "right": 'http://localhost:9200',
    }

    opts, args = getopt.getopt(sys.argv[1:], ':hl:r:b:t:',
                               ['help', 'left=', 'right=', 'batch-size=', 'type='])

    for o, v in opts:
        if o in ['-l', '--left']:
            clusters['left'] = v
        if o in ['-r', '--right']:
            clusters['right'] = v
        if o in ['-b', '--batch-size']:
            batch_size = int(v)
        if o in ['-t', '--type']:
            if clear_index_types:
                index_types.clear()
                clear_index_types = False
            index_types.append(v)
        if o in ['-h']:
            print_help(clusters, batch_size, index_types)
            sys.exit(1)

    if not len(args) == 1:
        print_help(clusters, batch_size, index_types)
        sys.exit(1)

    wiki = args[0]

    max_id = 0
    with requests.Session() as session:
        for cluster in clusters:
            index_uri = f'{clusters[cluster]}/{wiki}'
            max_id = max(max_id, fetch_max_id(session, index_uri))

    max_id += 5000
    min_per_process = batch_size * 10
    num_processes = min(40, int(math.ceil(max_id / float(min_per_process))))
    step = int(math.ceil(max_id / float(num_processes)))

    q = multiprocessing.Queue()
    workers = []
    try:
        listener = multiprocessing.Process(target=listen, args=(wiki, q))
        listener.start()

        for start in range(1, max_id, step):
            args = (wiki, clusters, index_types, batch_size, start, start + step, q, stats)
            worker = multiprocessing.Process(target=run, args=args)
            workers.append(worker)
            worker.start()
        for w in workers:
            w.join()
        q.put_nowait(None)
        listener.join()
        print(f'Results:'
              f'\n\ttotal: {stats[0]}'
              f'\n\terrors: {sum(stats[1:])} ({"{:.2f}".format(sum(stats[1:])/stats[0]*100)}%)'
              f'\n\t- not_found: {stats[1]}'
              f'\n\t- rev_missmatch: {stats[2]}'
              f'\n\t- title_missmatch: {stats[3]}')
    except KeyboardInterrupt:
        for w in workers:
            w.terminate()
        listener.terminate()


if __name__ == '__main__':
    main()
