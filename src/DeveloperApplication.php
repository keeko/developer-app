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
		$context = new RequestContext($this->prefix);
		$matcher = new UrlMatcher($routes, $context);
		
		$templatePath = sprintf('%s/%s/templates/', KEEKO_PATH_APPS, $this->model->getName());
		$loader = new \Twig_Loader_Filesystem($templatePath);
		$twig = new \Twig_Environment($loader);
	
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
							'base' => $this->base,
							'url' => $this->base . 'reference/' . $match['module'] . '.json',
							'module' => $this->findModuleBySlug($match['module'])
						]);
					} else {
						$content = $twig->render('reference_index.twig');
					}
					
					$main = $twig->render('reference.twig', [
						'base' => $this->base,
						'content' => $content,
						'modules' => $this->loadModules(),
						'current' => $current
					]);
					
					break;
					
				case 'index':
					$main = $twig->render('index.twig');
					break;
					
				case 'area':
					$main = $twig->render(sprintf('%s.twig', $match['area']));
					break;
					
				case 'topic':
					$main = $twig->render(sprintf('%s/%s.twig', $match['area']));
					break;
			}
	
			$response->setContent($twig->render('main.twig', [
				'base' => $this->base,
				'root' => $this->root,
				'app_root' => sprintf('%s/_keeko/apps/%s', $this->root, $this->model->getName()),
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
			$package = $this->packageManager->getModulePackage($mod->getName());
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
		$routes->add('json', new Route('/reference/{module}.json'));
		$routes->add('module', new Route('/reference/{module}'));
		$routes->add('area', new Route('/{area}'));
		$routes->add('topic', new Route('/{area}/{topic}'));
	
		return $routes;
	}
	

}