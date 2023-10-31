# Script to verify page creations are landing in the search indices

from datetime import datetime, timedelta
import requests

def find_candidates(start: datetime, end: datetime, batch_size: int = 10):
    if end > start:
        # works backwards, so the end time must come before the start time
        start, end = end, start

    source_filtering = ['title', 'redirect']
    query = {
        'action': 'query',
        'format': 'json',
        'formatversion': '2',

        'generator': 'recentchanges',
        'grcstart': start.isoformat() + 'Z',
        'grcend': end.isoformat() + 'Z',
        'grcnamespace': 0,
        'grclimit': batch_size,
        'grctype': 'new',

        'prop': 'cirrusdoc',
        'cdincludes': '|'.join(source_filtering),
    }

    while True:
        response = requests.get('https://en.wikipedia.org/w/api.php', params=query)
        response.raise_for_status()
        result = response.json()
        if 'continue' in result:
            query.update(result['continue'])
        for page in result['query']['pages']:
            if 'missing' in page:
                # No longer exists at the wiki, probably deleted but maybe moved without a redirect.
                continue
            yield {
                'page_id': page['pageid'], 
                'title': page['title'],
                'found': bool(page['cirrusdoc']),
            }
        if 'continue' not in result:
            return

def main():
    end = datetime(2023, 4, 18)
    start = end + timedelta(days=7)

    good = 0
    total = 0
    for meta in find_candidates(start, end):
        total += 1
        if meta['found']:
            good += 1
            continue
        print(meta['title'])

    print(f'\n\nFinal Stats:\ngood: {good}\nbad: {total-good}\ntotal: {total}')


if __name__ == "__main__":
    main()
