<?php

namespace XHGui\Controller;

use Exception;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Slim as App;
use XHGui\AbstractController;
use XHGui\Options\SearchOptions;
use XHGui\Searcher\SearcherInterface;

class RunController extends AbstractController
{
    /**
     * HTTP GET attribute name for comma separated filters
     */
    const FILTER_ARGUMENT_NAME = 'filter';

    /**
     * @var SearcherInterface
     */
    private $searcher;

    public function __construct(App $app, SearcherInterface $searcher)
    {
        parent::__construct($app);
        $this->searcher = $searcher;
    }

    public function index(Request $request, Response $response)
    {
        // The list changes whenever new profiles are recorded.
        // Generally avoid caching, but allow re-use in browser's bfcache
        // and by cache proxies for concurrent requests.
        // https://github.com/perftools/xhgui/issues/261
        $response->headers->set('Cache-Control', 'public, max-age=0');

        $search = [];
        $keys = ['date_start', 'date_end', 'url'];
        foreach ($keys as $key) {
            if ($request->get($key)) {
                $search[$key] = $request->get($key);
            }
        }
        $sort = $request->get('sort');

        $result = $this->searcher->getAll(new SearchOptions([
            'sort' => $sort,
            'page' => (int)$request->get('page', SearcherInterface::DEFAULT_PAGE),
            'direction' => $request->get('direction', SearcherInterface::DEFAULT_DIRECTION),
            'perPage' => (int)$this->app->config('page.limit'),
            'conditions' => $search,
            'projection' => true,
        ]));

        $title = 'Recent runs';
        $titleMap = [
            'wt' => 'Longest wall time',
            'cpu' => 'Most CPU time',
            'mu' => 'Highest memory use',
        ];
        if (isset($titleMap[$sort])) {
            $title = $titleMap[$sort];
        }

        $paging = [
            'total_pages' => $result['totalPages'],
            'page' => $result['page'],
            'sort' => $sort,
            'direction' => $result['direction'],
        ];

        $this->_template = 'runs/list.twig';
        $this->set([
            'paging' => $paging,
            'base_url' => 'home',
            'runs' => $result['results'],
            'date_format' => $this->app->config('date.format'),
            'search' => $search,
            'has_search' => strlen(implode('', $search)) > 0,
            'title' => $title,
        ]);
    }

    public function view(Request $request, Response $response)
    {
        // Permalink views to a specific run are meant to be public and immutable.
        // But limit the cache to only a short period of time (enough to allow
        // handling of abuse or other stampedes). This way we don't have to
        // deal with any kind of purging system for when profiles are deleted,
        // or for after XHGui itself is upgraded and static assets may be
        // incompatible etc.
        // https://github.com/perftools/xhgui/issues/261
        $response->headers->set('Cache-Control', 'public, max-age=60, must-revalidate');

        $detailCount = $this->app->config('detail.count');
        $result = $this->searcher->get($request->get('id'));

        $result->calculateSelf();

        // Self wall time graph
        $timeChart = $result->extractDimension('ewt', $detailCount);

        // Memory Block
        $memoryChart = $result->extractDimension('emu', $detailCount);

        // Watched Functions Block
        $watchedFunctions = [];
        foreach ($this->searcher->getAllWatches() as $watch) {
            $matches = $result->getWatched($watch['name']);
            if ($matches) {
                $watchedFunctions = array_merge($watchedFunctions, $matches);
            }
        }

        if (false !== $request->get(self::FILTER_ARGUMENT_NAME, false)) {
            $profile = $result->sort('ewt', $result->filter($result->getProfile(), $this->getFilters()));
        } else {
            $profile = $result->sort('ewt', $result->getProfile());
        }

        $this->_template = 'runs/view.twig';
        $this->set([
            'profile' => $profile,
            'result' => $result,
            'wall_time' => $timeChart,
            'memory' => $memoryChart,
            'watches' => $watchedFunctions,
            'date_format' => $this->app->config('date.format'),
        ]);
    }

    /**
     * @return array
     */
    protected function getFilters()
    {
        $request = $this->app->request();
        $filterString = $request->get(self::FILTER_ARGUMENT_NAME);
        if (strlen($filterString) > 1 && $filterString !== 'true') {
            $filters = array_map('trim', explode(',', $filterString));
        } else {
            $filters = $this->app->config('run.view.filter.names');
        }

        return $filters;
    }

    public function deleteForm(Request $request)
    {
        $id = $request->get('id');
        if (!is_string($id) || !strlen($id)) {
            throw new Exception('The "id" parameter is required.');
        }

        // Get details
        $result = $this->searcher->get($id);

        $this->_template = 'runs/delete-form.twig';
        $this->set([
            'run_id' => $id,
            'result' => $result,
        ]);
    }

    public function deleteSubmit(Request $request)
    {
        $id = $request->post('id');
        // Don't call profilers->delete() unless $id is set,
        // otherwise it will turn the null into a MongoId and return "Successful".
        if (!is_string($id) || !strlen($id)) {
            // Form checks this already,
            // only reachable by handcrafted or malformed requests.
            throw new Exception('The "id" parameter is required.');
        }

        // Delete the profile run.
        $this->searcher->delete($id);

        $this->app->flash('success', 'Deleted profile ' . $id);

        $this->app->redirect($this->app->urlFor('home'));
    }

    public function deleteAllForm()
    {
        $this->_template = 'runs/delete-all-form.twig';
    }

    public function deleteAllSubmit()
    {
        // Delete all profile runs.
        $this->searcher->truncate();

        $this->app->flash('success', 'Deleted all profiles');

        $this->app->redirect($this->app->urlFor('home'));
    }

    public function url(Request $request)
    {
        $pagination = [
            'sort' => $request->get('sort'),
            'direction' => $request->get('direction'),
            'page' => $request->get('page'),
            'perPage' => $this->app->config('page.limit'),
        ];

        $search = [];
        $keys = ['date_start', 'date_end', 'limit', 'limit_custom'];
        foreach ($keys as $key) {
            $search[$key] = $request->get($key);
        }

        $runs = $this->searcher->getForUrl(
            $request->get('url'),
            $pagination,
            $search
        );

        if (isset($search['limit_custom']) &&
            strlen($search['limit_custom']) > 0 &&
            $search['limit_custom'][0] === 'P'
        ) {
            $search['limit'] = $search['limit_custom'];
        }

        $chartData = $this->searcher->getPercentileForUrl(
            90,
            $request->get('url'),
            $search
        );

        $paging = [
            'total_pages' => $runs['totalPages'],
            'sort' => $pagination['sort'],
            'page' => $runs['page'],
            'direction' => $runs['direction'],
        ];

        $this->_template = 'runs/url.twig';
        $this->set([
            'paging' => $paging,
            'base_url' => 'url.view',
            'runs' => $runs['results'],
            'url' => $request->get('url'),
            'chart_data' => $chartData,
            'date_format' => $this->app->config('date.format'),
            'search' => array_merge($search, ['url' => $request->get('url')]),
        ]);
    }

    public function compare(Request $request)
    {
        $baseRun = $headRun = $candidates = $comparison = null;
        $paging = [];

        if ($request->get('base')) {
            $baseRun = $this->searcher->get($request->get('base'));
        }

        if ($baseRun && !$request->get('head')) {
            $pagination = [
                'direction' => $request->get('direction'),
                'sort' => $request->get('sort'),
                'page' => $request->get('page'),
                'perPage' => $this->app->config('page.limit'),
            ];
            $candidates = $this->searcher->getForUrl(
                $baseRun->getMeta('simple_url'),
                $pagination
            );

            $paging = [
                'total_pages' => $candidates['totalPages'],
                'sort' => $pagination['sort'],
                'page' => $candidates['page'],
                'direction' => $candidates['direction'],
            ];
        }

        if ($request->get('head')) {
            $headRun = $this->searcher->get($request->get('head'));
        }

        if ($baseRun && $headRun) {
            $comparison = $baseRun->compare($headRun);
        }

        $this->_template = 'runs/compare.twig';
        $this->set([
            'base_url' => 'run.compare',
            'base_run' => $baseRun,
            'head_run' => $headRun,
            'candidates' => $candidates,
            'url_params' => $request->get(),
            'date_format' => $this->app->config('date.format'),
            'comparison' => $comparison,
            'paging' => $paging,
            'search' => [
                'base' => $request->get('base'),
                'head' => $request->get('head'),
            ],
        ]);
    }

    public function symbol(Request $request)
    {
        $id = $request->get('id');
        $symbol = $request->get('symbol');

        $profile = $this->searcher->get($id);
        $profile->calculateSelf();
        list($parents, $current, $children) = $profile->getRelatives($symbol);

        $this->_template = 'runs/symbol.twig';
        $this->set([
            'symbol' => $symbol,
            'id' => $id,
            'main' => $profile->get('main()'),
            'parents' => $parents,
            'current' => $current,
            'children' => $children,
        ]);
    }

    public function symbolShort(Request $request)
    {
        $id = $request->get('id');
        $threshold = $request->get('threshold');
        $symbol = $request->get('symbol');
        $metric = $request->get('metric');

        $profile = $this->searcher->get($id);
        $profile->calculateSelf();
        list($parents, $current, $children) = $profile->getRelatives($symbol, $metric, $threshold);

        $this->_template = 'runs/symbol-short.twig';
        $this->set([
            'symbol' => $symbol,
            'id' => $id,
            'main' => $profile->get('main()'),
            'parents' => $parents,
            'current' => $current,
            'children' => $children,
        ]);
    }

    public function callgraph(Request $request)
    {
        $profile = $this->searcher->get($request->get('id'));

        $this->_template = 'runs/callgraph.twig';
        $this->set([
            'profile' => $profile,
            'date_format' => $this->app->config('date.format'),
        ]);
    }

    public function callgraphData(Request $request, Response $response)
    {
        $profile = $this->searcher->get($request->get('id'));
        $metric = $request->get('metric') ?: 'wt';
        $threshold = (float)$request->get('threshold') ?: 0.01;
        $callgraph = $profile->getCallgraph($metric, $threshold);

        $response['Content-Type'] = 'application/json';

        return $response->body(json_encode($callgraph));
    }

    public function callgraphDataDot(Request $request, Response $response)
    {
        $profile = $this->searcher->get($request->get('id'));
        $metric = $request->get('metric') ?: 'wt';
        $threshold = (float)$request->get('threshold') ?: 0.01;
        $callgraph = $profile->getCallgraphNodes($metric, $threshold);

        $response['Content-Type'] = 'application/json';

        return $response->body(json_encode($callgraph));
    }
}
