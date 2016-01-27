<?php
namespace keeko\application\developer;

use keeko\core\model\ModuleQuery;
use keeko\core\package\AbstractApplication;
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
		$context = new RequestContext($this->getAppPath());
		$matcher = new UrlMatcher($routes, $context);

		$prefs = $this->service->getPreferenceLoader()->getSystemPreferences();
		
		try {
			$path = str_replace('//', '/', '/' . $this->getDestinationPath());
			$match = $matcher->match($path);
			$main = print_r($match, true);
			$route = $match['_route'];
			$css = [];
			$scripts = [];
				
			switch ($route) {
				case 'json':
					$module = $this->findModuleBySlug($match['module']);
						
					if ($module !== null) {
						$extra = $module['package']->getExtra();
						$api = $extra['keeko']['module']['api'];
						$api['basePath'] = trim($prefs->getApiUrl() . $module['slug'], '/');
		
						return new JsonResponse($api);
					}
					break;
		
				case 'reference':
				case 'module':
					$current = '';
					$content = $main;
					if ($route == 'module') {
						$scripts = [
							'/assets/swagger-ui/dist/lib/shred.bundle.js',
							'/assets/swagger-ui/dist/lib/jquery.slideto.min.js',
							'/assets/swagger-ui/dist/lib/jquery.wiggle.min.js',
							'/assets/swagger-ui/dist/lib/jquery.ba-bbq.min.js',
							'/assets/swagger-ui/dist/lib/handlebars-1.0.0.js',
							'/assets/swagger-ui/dist/lib/underscore-min.js',
							'/assets/swagger-ui/dist/lib/backbone-min.js',
							'/assets/swagger-ui/dist/lib/swagger.js',
							'/assets/swagger-ui/dist/swagger-ui.min.js',
							'/assets/swagger-ui/dist/lib/highlight.7.3.pack.js'
						];

						$css = [
							'/assets/swagger-ui/dist/css/screen.css'
						];
		
						$current = $match['module'];
						$content = $this->render('/keeko/developer-app/templates/api.twig', [
							'base' => $this->getAppUrl(),
							'url' => $this->getAppUrl() . 'reference/' . $match['module'] . '.json',
							'module' => $this->findModuleBySlug($match['module'])
						]);
					} else {
						$content = $this->render('/keeko/developer-app/templates/reference/index.twig');
					}
						
					$main = $this->render('/keeko/developer-app/templates/reference.twig', [
						'base' => $this->getAppUrl(),
						'content' => $content,
						'modules' => $this->loadModules(),
						'current' => $current
					]);
						
					break;
						
				case 'index':
					$main = $this->render('/keeko/developer-app/templates/index.twig', [
						'plattform_name' => $prefs->getPlattformName(),
						'base' => $this->getAppUrl()
					]);
					break;
						
				case 'area':
				case 'topic':
					$current = isset($match['topic']) ? $match['topic'] : 'index';
					$content = $this->render(sprintf('/keeko/developer-app/templates/%s/%s.twig', $match['area'], $current), [
						'base' => $this->getAppUrl(),
						'api_url' => $prefs->getApiUrl()
					]);
					$main = $this->render(sprintf('/keeko/developer-app/templates/%s.twig', $match['area']), [
						'content' => $content,
						'menu' => $this->getMenu($match['area']),
						'current' => $current,
						'base' => $this->getAppUrl()
					]);
					break;
			}

			$response->setContent($this->render('/keeko/developer-app/templates/main.twig', [
				'plattform_name' => $prefs->getPlattformName(),
				'root' => $this->getRootUrl(),
				'base' => $this->getAppUrl(),
				'destination' => $this->getDestinationPath(),
				'app_root' => sprintf('%s/_keeko/apps/%s', $this->getRootUrl(), $this->model->getName()),
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
		$mods = ModuleQuery::create()->filterByApi(true)->find();
		foreach ($mods as $mod) {
			$package = $this->service->getPackageManager()->getModulePackage($mod->getName());
			$routes = [];
			foreach ($package->getExtra()['keeko']['module']['api']['apis'] as $route) {
				foreach ($route['operations'] as $op) {
					$routes[str_replace('-', '_', $op['nickname'])] = [
						'method' => $op['method'],
						'path' => $route['path']
					];
				}
			}
			$modules[] = [
				'title' => $mod->getTitle(),
				'slug' => $mod->getSlug(),
				'module' => $mod,
				'package' => $package,
				'routes' => $routes
			];
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
