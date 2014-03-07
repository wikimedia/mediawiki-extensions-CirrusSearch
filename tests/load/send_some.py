 #!/usr/bin/env python


import sys
import urllib
import urllib2
from multiprocessing import Process, Queue
from Queue import Full
import time
import calendar


def send_line(search, destination):
    params = "fulltext=Search&srbackend=CirrusSearch"
    url = "%s/%s?%s" % (destination, search, params)
    urllib2.urlopen(url)
    print "Fetched " + url


def send_lines(every, jobs, destination):
    queue = Queue(jobs)  # Only allow a backlog of one per job

    # Spawn jobs.  Note that we just spawn them as daemon because we don't
    # want to bother signaling them when the main process is done and we don't
    # care if they die when it finishes either.  In fact, we'd love for them
    # to die immediately because we want to stop sending requests when the main
    # process stops.
    def work(queue):
        while True:
            try:
                search = queue.get()
                send_line(search, destination)
            except (KeyboardInterrupt, SystemExit):
                break
            except:
                continue
    for i in range(jobs):
        p = Process(target=work, args=(queue,))
        p.daemon = True
        p.start()

    # Got to read stdin line by line even on old pythons....
    line = sys.stdin.readline()
    n = 1
    last_time = None
    last_start = time.time()
    while line:
        if n != every:
            n += 1
            line = sys.stdin.readline()
            continue
        s = line.strip().split("\t")
        target_time = calendar.timegm(
            time.strptime(s[1][:-1] + "UTC", "%Y-%m-%dT%H:%M:%S%Z"))
        if last_time is None:
            last_time = target_time
        elif last_time < target_time:
            now = time.time()
            time_since_last_time = now - last_start
            wait_time = target_time - last_time - time_since_last_time
            lag = last_start - last_time
            last_time = target_time
            last_start = now
            if wait_time > 0:
                print "Sleeping %s to stay %s behind the logged time." % \
                    (wait_time, lag)
                time.sleep(wait_time)
        try:
            queue.put(urllib.unquote(s[3]), False)
        except Full:
            print "Couldn't keep up so dropping the request"
        # send_line(line, destination)
        n = 1
        line = sys.stdin.readline()


if __name__ == "__main__":
    from optparse import OptionParser
    parser = OptionParser(usage="usage: %prog [options] destination")
    parser.add_option("-n", dest="every", type="int", default=1, metavar="N",
                      help="send every Nth search")
    parser.add_option("-j", "--jobs", type="int", default=1, metavar="JOBS",
                      help="number of processes used to send searches")
    parser.add_option("-d", "--destination", dest="destination", type="string",
                      metavar="DESTINATION",
                      default="http://127.0.0.1:8080/wiki/Special:Search",
                      help="where to send the searches")
    (options, args) = parser.parse_args()
    try:
        send_lines(options.every, options.jobs, options.destination)
    except KeyboardInterrupt:
        pass  # This is how we expect to exit anyway
