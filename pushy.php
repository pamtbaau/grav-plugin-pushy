<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\Pushy\PushyRepo;
use Grav\Plugin\Pushy\GitUtils;

/**
 * Class PushyPlugin
 * @package Grav\Plugin
 */
class PushyPlugin extends Plugin {
	/** @var PushyRepo */
	protected $repo;

	/** @var string */
	protected $admin_route = 'publish';

	/**
	 * @return array
	 */
	public static function getSubscribedEvents(): array {
		return [
			'onPluginsInitialized' => [
				['onPluginsInitialized', 0],
				],
			];
	}

	/**
	 * Composer autoload
	 *
	 * @return ClassLoader
	 */
	public function autoload(): ClassLoader	{
		return require __DIR__ . '/vendor/autoload.php';
	}

	/**
	 * Initialize the class instance
	 */
	public function init():void {
		$this->repo = new PushyRepo();
	}

	/**
	 * Initialize the plugin
	 */
	public function onPluginsInitialized(): void {
		$this->init();

		if ($this->isAdmin()) {
			$this->enable([
				'onAdminTwigTemplatePaths'  => ['setAdminTwigTemplatePaths', 0],
				'onAdminMenu' => ['showPublishingMenu', 0],
				'onTwigSiteVariables' => ['setTwigSiteVariables', 0],
				]);
		}

		else {
			$this->enable([
				'onPageInitialized'      => ['serveHooks', 0],
				]);
		}
	}

	/**
	 * Get admin page template
	 */
	public function setAdminTwigTemplatePaths(Event $event): void {
		$paths = $event['paths'];
		$paths[] = __DIR__ . DS . 'admin/templates';
		$event['paths'] = $paths;
	}

	/**
	 * Show the publishing menu item(s) in Admin
	 */
	public function showPublishingMenu(): void {
		$isInitialized = GitUtils::isGitInitialized();
		// TODO: test for GitUtils::isGitInstalled()
		$menuLabel = $isInitialized ? 'Publish' : 'Publishing';
		$options = [
			'hint' => $isInitialized ? 'Publish' : 'Publication settings',
			'location' => 'pages',
			'route' => $isInitialized ? $this->admin_route : "plugins/{$this->name}",
			'icon' => 'fa-' . ($isInitialized ? $this->grav['plugins']->get($this->name)->blueprints()->get('icon') : 'cog'),
			// 'class' => '',
			// 'data' => [],
			];

		$this->grav['twig']->plugins_hooked_nav[$menuLabel] = $options; // TODO: make this configurable in YAML/blueprint
	}

	/**
	 * Set any special variables for Twig templates
	 */
	public function setTwigSiteVariables($event): void {
		$publish_path = $this->config->get('plugins.admin.route') . DS . $this->admin_route;
		$route = $this->grav['uri']->path();

		if ($route == $publish_path) {
			$twig = $this->grav['twig'];
			$twig->twig_vars['git_index'] = $this->repo->statusSelect(); # TRUE, $env='index', $select='MTDRCA');
		}
	}

	public function serveHooks() {

		$webhooks = $this->config->get("plugins.{$this->name}.webhooks");
		if (!($webhooks['enabled'] ?? FALSE)) {
			return;
		}
		$page = $this->grav['page'] ?? NULL; // CHECKME: may not need this
		// dump($this->grav['uri']->uri(), is_null($page->route())); return;

		if (strpos($this->grav['uri']->uri(), $webhooks['path']) === 0) { // is_null($page->route()) &&

			if (strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {
				http_response_code(405);
				exit;
			}

			if ($webhooks['secret'] ?? false) {
				if (!$this->isWebhookAuthenticated($webhooks['secret'])) {
					$this->jsonRespond(401, [
						'status' => 'error',
						'message' => 'Unauthorized request',
						]);
				}
			}

			// TODO: check for $this->grav['uri']->uri() == $webhooks['path'] here and provide a service catalogue??

			foreach ($webhooks['endpoints'] as $hook => $hook_properties) {

				// match on the endpoint
				$endpoint = strtolower(implode('/', [$webhooks['path'], $hook]));
				if(strtolower($this->grav['uri']->uri()) ==  $endpoint) {

					// TODO: check declared conditions
					try {
						// TODO: perform the named scheduled task

						$this->jsonRespond(202, [
							'status' => 'success',
							'message' => 'Operation succeeded',
							'debug' => $hook_properties,
							]);
					}
					catch (\Exception $e) {
						$this->jsonRespond(500, [
							'status' => 'error',
							'message' => 'Operation failed',
							'debug' => $hook_properties,
							]);
					}
				}
			}

		}
	}

	/**
	 * Returns true if the request contains a valid signature or token
	 * @param  string $secret local secret
	 * @return bool           whether or not the request is authorized
	 */
	// copied from GitSync base class method isRequestAuthorized()
	public function isWebhookAuthenticated($secret): bool {
		if (isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
			$payload = file_get_contents('php://input') ?: '';

			return $this->isGithubSignatureValid($secret, $_SERVER['HTTP_X_HUB_SIGNATURE'], $payload);
		}

		if (isset($_SERVER['HTTP_X_GITLAB_TOKEN'])) {
			return $this->isGitlabTokenValid($secret, $_SERVER['HTTP_X_GITLAB_TOKEN']);
		}
		else {
			$payload = file_get_contents('php://input');
			return $this->isGiteaSecretValid($secret, $payload);
		}

		return FALSE;
	}

	/**
	 * Hashes the webhook request body with the client secret and checks if it matches the webhook signature header
	 * @param  string $secret The webhook secret
	 * @param  string $signatureHeader The signature of the webhook request
	 * @param  string $payload The webhook request body
	 * @return bool            whether the signature is valid or not
	 */
	// copied from GitSync base class method but uses more secure hash_equals()
	private function isGithubSignatureValid($secret, $signatureHeader, $payload): bool {
		[$algorithm, $signature] = explode('=', $signatureHeader);

		return hash_equals($signature, hash_hmac($algorithm, $payload, $secret));
	}

	/**
	 * Returns true if given Gitlab token matches secret
	 * @param  string $secret local secret
	 * @param  string $token token received from Gitlab webhook request
	 * @return bool          whether or not secret and token match
	 */
	// copied from GitSync base class method but uses more secure hash_equals()
	// TODO: untested
	private function isGitlabTokenValid($secret, $token): bool {
		return hash_equals($secret === $token);
	}

	/**
	 * Returns true if secret contained in the payload matches the client secret
	 * @param  string $secret The webhook secret
	 * @param  string $payload The webhook request body
	 * @return bool            whether the client secret matches the payload secret or not
	 */
	// copied from GitSync base class method but uses more secure hash_equals()
	// TODO: untested
	private function isGiteaSecretValid($secret, $payload): bool {
		$payload = json_decode($payload, TRUE);
		if (!empty($payload) && isset($payload['secret'])) {
			return hash_equals($secret, $payload['secret']);
		}
		return FALSE;
	}

	/**
	 * Provide a HTTP status and JSON response and exit
	 * @param  int    $http_status   HTTP status number to return
	 * @param  array  $proto_payload Payload as array to be served as JSON
	 * @return void
	 */
	private function jsonRespond(int $http_status, array $proto_payload): void {
		header('Content-Type: application/json');
		http_response_code($http_status);
		echo json_encode($proto_payload);
		exit;
	}

}
