import json
import sys

batch_size = 2000
clusters = {
    'eqiad': "search.svc.eqiad.wmnet",
    'codfw': "search.svc.codfw.wmnet",
}


def do_request(session, cluster, wiki, type, ids):
    host = clusters[cluster]
    path = "%(wiki)s_%(type)s/page/_mget" % locals()
    url = 'http://%(host)s:9200/%(path)s?fields=_id' % locals()
    payload = json.dumps({'ids': ids})

    r = session.post(url, data=payload)
    docs = json.loads(r.text)['docs']
    by_id = {}
    for doc in docs:
        by_id[doc['_id']] = doc['found']
    return by_id


def request(session, cluster, wiki, type, ids):
    attempt = 0
    while attempt < 3:
        try:
            return do_request(session, cluster, wiki, type, ids)
        except:
            attempt += 1
    raise Exception('failed requesting data...')


def compare(wiki, q, lists):
    head = lists.keys()[0]
    other = lists.keys()[1:]
    expected_len = len(lists[head])
    for cluster in other:
        if not len(lists[cluster]) == expected_len:
            raise Exception("Counts dont match: %d != %d" %
                            (len(lists[cluster]), expected_len))
    for id, found in lists[head].iteritems():
        error = False
        for cluster in other:
            if not error and not found == lists[cluster][id]:
                error = True
                q.put_nowait(id)
            del lists[cluster][id]
    for cluster in other:
        if len(lists[cluster]) > 0:
            raise Exception("Doc(s) returned from %s but not %s: %s" %
                            (head, cluster, ",".join(lists[cluster].keys())))


def run(wiki, start, end, q):
    import requests

    session = requests.Session()
    for next in xrange(start, end, batch_size):
        ids = range(next, next + batch_size)
        for type in ['content', 'general']:
                lists = {}
                for cluster in clusters.keys():
                    lists[cluster] = request(session, cluster, wiki, type, ids)
                compare(wiki, q, lists)


def listen(wiki, q):
    line_format = ("mwscript extensions/CirrusSearch/maintenance/saneitize.php"
                   " --wiki %s --cluster %s --fromId %s --toId %s")
    while True:
        try:
            id = q.get()
            if id is None:
                break
	    for cluster in clusters.keys():
                print(line_format % (wiki, cluster, id, id))
        except (KeyboardInterrupt, SystemExit):
            raise
        except:
            import traceback
            print >> sys.stderr, 'Whoops! Problem:'
            traceback.print_exc(file=sys.stderr)


def get_max_id(wiki):
    from subprocess import Popen, PIPE
    p = Popen(['sql', wiki], stdin=PIPE, stdout=PIPE)
    out, err = p.communicate(input='select max(page_id) from page')
    if p.returncode > 0:
        raise Exception("Failed requesting max id, invalid wiki?")
    return int(out.split("\n")[1])


if __name__ == "__main__":
    import math
    import multiprocessing

    if not len(sys.argv) == 2:
        print("Usage: %s <wiki>\n" % (sys.argv[0]))
        sys.exit(1)

    wiki = sys.argv[1]
    max_id = get_max_id(wiki) + 5000
    min_per_process = batch_size * 10
    num_processes = min(40, int(math.ceil(max_id / float(min_per_process))))
    step = int(math.ceil(max_id/float(num_processes)))

    q = multiprocessing.Queue()
    workers = []
    try:
        listener = multiprocessing.Process(target=listen, args=(wiki, q))
        listener.start()

        for start in range(1, max_id, step):
            args = (wiki, start, start + step, q)
            worker = multiprocessing.Process(target=run, args=args)
            workers.append(worker)
            worker.start()
        for w in workers:
            w.join()
        q.put_nowait(None)
        listener.join()
    except KeyboardInterrupt:
        for w in workers:
            w.terminate()
        listener.terminate()
