<?php

namespace keeko\application\developer;

use keeko\core\application\AbstractApplication;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use keeko\core\model\Api;
use keeko\core\model\ApiQuery;
use keeko\core\model\Module;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\Response;
use keeko\core\model\ModuleQuery;

class DeveloperApplication extends AbstractApplication {
	
	private $modules;
	
	/* (non-PHPdoc)
	 * @see \keeko\core\application\AbstractApplication::run()
	*/
	public function run(Request $request, $path) {
	
		$routes = $this->generateRoutes();
		$response = new Response();
		$context = new RequestContext($this->getAppPath());
		$matcher = new UrlMatcher($routes, $context);
		
		$templatePath = sprintf('%s/%s/templates/', KEEKO_PATH_APPS, $this->model->getName());
		$loader = new \Twig_Loader_Filesystem($templatePath);
		$twig = new \Twig_Environment($loader);
		
		$prefs = $this->service->getPreferenceLoader()->getSystemPreferences();
	
		try {
			$path = str_replace('//', '/', '/' . $path);
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
							'components/swagger-ui/dist/lib/shred.bundle.js',
							'components/swagger-ui/dist/lib/jquery.slideto.min.js',
							'components/swagger-ui/dist/lib/jquery.wiggle.min.js',
							'components/swagger-ui/dist/lib/jquery.ba-bbq.min.js',
							'components/swagger-ui/dist/lib/handlebars-1.0.0.js',
							'components/swagger-ui/dist/lib/underscore-min.js',
							'components/swagger-ui/dist/lib/backbone-min.js',
							'components/swagger-ui/dist/lib/swagger.js',
							'components/swagger-ui/dist/swagger-ui.min.js',
							'components/swagger-ui/dist/lib/highlight.7.3.pack.js'
						];
						
						$css = ['components/swagger-ui/dist/css/screen.css'];
						
						$current = $match['module'];
						$content = $twig->render('api.twig', [
							'base' => $this->getAppUrl(),
							'url' => $this->getAppUrl() . 'reference/' . $match['module'] . '.json',
							'module' => $this->findModuleBySlug($match['module'])
						]);
					} else {
						$content = $twig->render('reference/index.twig');
					}
					
					$main = $twig->render('reference.twig', [
						'base' => $this->getAppUrl(),
						'content' => $content,
						'modules' => $this->loadModules(),
						'current' => $current
					]);
					
					break;
					
				case 'index':
					$main = $twig->render('index.twig', [
						'plattform_name' => $prefs->getPlattformName(),
						'base' => $this->getAppUrl()
					]);
					break;
					
				case 'area':
				case 'topic':
					$current = isset($match['topic']) ? $match['topic'] : 'index';
					$content = $twig->render(sprintf('%s/%s.twig', $match['area'], $current), [
						'base' => $this->getAppUrl(),
						'api_url' => $prefs->getApiUrl()
					]);
					$main = $twig->render(sprintf('%s.twig', $match['area']), [
						'content' => $content,
						'menu' => $this->getMenu($match['area']),
						'current' => $current,
						'base' => $this->getAppUrl()
					]);
					break;
			}
	
			$response->setContent($twig->render('main.twig', [
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