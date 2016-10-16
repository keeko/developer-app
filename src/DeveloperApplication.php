<?php
namespace keeko\application\developer;

use gossi\swagger\Swagger;
use keeko\core\model\ModuleQuery;
use keeko\framework\foundation\AbstractApplication;
use phootwork\json\Json;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Developer Application
 *
 * @license MIT
 * @author gossi
 */
class DeveloperApplication extends AbstractApplication {

	private $modules;

	/**
	 * @param Request $request
	 */
	public function run(Request $request) {
		$routes = $this->generateRoutes();
		$response = new Response();
		$context = new RequestContext($this->uri->getBasepath());
		$matcher = new UrlMatcher($routes, $context);

		$prefs = $this->service->getPreferenceLoader()->getSystemPreferences();

		try {
			$match = $matcher->match($this->getDestination());
			$main = print_r($match, true);
			$route = $match['_route'];
			$css = [];
			$scripts = [];

			switch ($route) {
				case 'json':
					$repo = $this->service->getResourceRepository();
					$module = $this->findModuleBySlug($match['module']);

					if ($module !== null && $module['model'] !== null) {
						$components = parse_url($prefs->getApiUrl() . $module['slug']);

						$prefs = $this->service->getPreferenceLoader()->getSystemPreferences();
						$model = $module['model'];
						$filename = sprintf('/packages/%s/api.json', $model->getName());
						$json = Json::decode($repo->get($filename)->getBody());
						$swagger = new Swagger($json);
						$swagger->getInfo()->setVersion($prefs->getApiVersion());
						$swagger->setHost($components['host']);
						$swagger->setBasePath($components['path']);
						$swagger->getSchemes()->add($components['scheme']);
						$swagger->getConsumes()->add('application/vnd.api+json');
						$swagger->getProduces()->add('application/vnd.api+json');

						$response = new JsonResponse($swagger->toArray());
						$response->setEncodingOptions(Json::HEX_TAG | Json::HEX_APOS | Json::HEX_AMP | Json::HEX_QUOT | Json::UNESCAPED_SLASHES);
						return $response;
					}
					break;

				case 'reference':
				case 'module':
					$current = '';
					$content = $main;
					if ($route == 'module') {
						$scripts = [
							'/assets/jquery-migrate/jquery-migrate.min.js',
							'/assets/swagger-ui/dist/lib/jquery.slideto.min.js',
							'/assets/swagger-ui/dist/lib/jquery.wiggle.min.js',
							'/assets/swagger-ui/dist/lib/jquery.ba-bbq.min.js',
							'/assets/swagger-ui/dist/lib/handlebars-2.0.0.js',
							'/assets/swagger-ui/dist/lib/underscore-min.js',
							'/assets/swagger-ui/dist/lib/backbone-min.js',
							'/assets/swagger-ui/dist/swagger-ui.min.js',
							'/assets/swagger-ui/dist/lib/highlight.7.3.pack.js',
							'/assets/swagger-ui/dist/lib/jsoneditor.min.js',
							'/assets/swagger-ui/dist/lib/marked.js'
						];

						$css = [
							'/assets/swagger-ui/dist/css/screen.css'
						];

						$current = $match['module'];
						$content = $this->render('/keeko/developer-app/templates/api.twig', [
							'base' => $this->getBaseUrl(),
							'url' => $this->getBaseUrl() . '/reference/' . $match['module'] . '.json',
							'module' => $this->findModuleBySlug($match['module'])
						]);
					} else {
						$content = $this->render('/keeko/developer-app/templates/reference/index.twig');
					}

					$main = $this->render('/keeko/developer-app/templates/reference.twig', [
						'base' => $this->getBaseUrl(),
						'content' => $content,
						'modules' => $this->loadModules(),
						'current' => $current
					]);

					break;

				case 'index':
					$main = $this->render('/keeko/developer-app/templates/index.twig', [
						'plattform_name' => $prefs->getPlattformName(),
						'base' => $this->getBaseUrl()
					]);
					break;

				case 'area':
				case 'topic':
					$current = isset($match['topic']) ? $match['topic'] : 'index';
					$content = $this->render(sprintf('/keeko/developer-app/templates/%s/%s.twig', $match['area'], $current), [
						'base' => $this->getBaseUrl(),
						'api_url' => $prefs->getApiUrl()
					]);
					$main = $this->render(sprintf('/keeko/developer-app/templates/%s.twig', $match['area']), [
						'content' => $content,
						'menu' => $this->getMenu($match['area']),
						'current' => $current,
						'base' => $this->getBaseUrl()
					]);
					break;
			}

			$response->setContent($this->render('/keeko/developer-app/templates/main.twig', [
				'plattform_name' => $prefs->getPlattformName(),
				'root' => $this->getBaseUrl(),
				'base' => $this->getBaseUrl(),
				'destination' => $this->getDestination(),
				'styles' => $css,
				'scripts' => $scripts,
				'main' => $main
			]));

		} catch (ResourceNotFoundException $e) {
			$response->setStatusCode(Response::HTTP_NOT_FOUND);
		}
		return $response;
	}

	private function loadModules() {
		if (!empty($this->modules)) {
			return $this->modules;
		}
		$modules = [];
		$models = ModuleQuery::create()->filterByApi(true)->find();
		$repo = $this->service->getResourceRepository();

		foreach ($models as $model) {
			$package = $this->service->getPackageManager()->getPackage($model->getName());
			$filename = sprintf('/packages/%s/api.json', $model->getName());
			if ($repo->contains($filename)) {
				$routes = [];
				$json = Json::decode($repo->get($filename)->getBody());
				$swagger = new Swagger($json);
				foreach ($swagger->getPaths() as $path) {
					/* @var $path Path */
					foreach (Swagger::$METHODS as $method) {
						if ($path->hasOperation($method)) {
							$op = $path->getOperation($method);
							$actionName = $op->getOperationId();
							$routes[str_replace('-', '_', $actionName)] = [
								'method' => $method,
								'path' => $path->getPath()
							];
						}
					}
				}
				$modules[] = [
					'title' => $model->getTitle(),
					'slug' => $package->getKeeko()->getModule()->getSlug(),
					'model' => $model,
// 					'package' => ,
					'routes' => $routes
				];
			}
		}
		$this->modules = $modules;
		return $modules;
	}

	private function findModuleBySlug($slug) {
		$modules = $this->loadModules();
		foreach ($modules as $module) {
			if ($module['slug'] == $slug) {
				return $module;
			}
		}

		return null;
	}

	private function generateRoutes() {
		$routes = new RouteCollection();
		$routes->add('index', new Route('/'));
		$routes->add('reference', new Route('/reference'));
		$routes->add('json', new Route('/reference/{module}.json', [], ['module' => '.+']));
		$routes->add('module', new Route('/reference/{module}'));
		$routes->add('area', new Route('/{area}'));
		$routes->add('topic', new Route('/{area}/{topic}'));

		return $routes;
	}

	private function getMenu($area) {
		$menu = [];
		switch ($area) {
			case 'guides':
				$menu = [
					'auth' => [
					'title' => 'Authentication',
					'chapters' => [
						'basic' => 'Basic',
						'token' => 'Token'
					]
				],
				'error-handling' => [
					'title' => 'Error Handling',
					'chapters' => [
						'http-status-codes' => 'HTTP-Status Codes',
						'exceptions' => 'Exceptions'
					],
				]
			];
			break;
		}

		return $menu;
	}
}
