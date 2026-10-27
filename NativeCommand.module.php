<?php namespace ProcessWire;

/**
 * Native Command — keyboard command palette for ProcessWire (Alt+K / Option+K by default)
 *
 * Press Alt+K anywhere — in the admin or on the front-end — and act on the page
 * you are looking at: publish, rename, set any field, duplicate, move, trash, undo.
 * Bulk actions over any selector, instant answers, search, shortcuts, and an API
 * other modules can add commands to.
 *
 * Register commands from any module or /site/ready.php:
 *
 *   $wire->addHookAfter('NativeCommand::registerCommands', function(HookEvent $e) {
 *     $nc = $e->object;
 *     $nc->addCommand('shop.orders-today', [
 *       'title' => 'Orders today',
 *       'permission' => 'shop-view',
 *       'mutates' => false,
 *       'handler' => function(array $args, ?Page $page, NativeCommand $nc) {
 *         return ['message' => 'Orders today', 'rows' => [['Count', 12], ['Revenue', '940 €']]];
 *       },
 *     ]);
 *   });
 *
 * See README.md for the full command definition reference.
 *
 * @author Gregor Rojko (Pyxios) — https://pyxios.com
 * @license MIT
 */
class NativeCommand extends WireData implements Module, ConfigurableModule {

	const ENDPOINT = '_nc';
	const LOG = 'native-command';

	public static function getModuleInfo() {
		return [
			'title' => 'Native Command',
			'summary' => 'Keyboard command palette (Alt+K) for the admin and the front-end: edit any field, bulk-edit with selectors, undo, search and jump anywhere.',
			'version' => 103,
			'author' => 'Gregor Rojko (Pyxios)',
			'href' => 'https://github.com/Roychgod/NativeCommand',
			'icon' => 'terminal',
			'autoload' => true,
			'singular' => true,
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=7.4'],
		];
	}

	protected static $allSources = ['nav', 'pages', 'templates', 'fields', 'modules', 'users', 'actions', 'commands'];
	protected static $argTypes = ['text', 'int', 'page', 'template', 'field', 'choice', 'pages'];

	const BULK_MAX = 500;

	/** @var array Registered commands, keyed by id */
	protected $commands = [];

	/** @var bool */
	protected $commandsLoaded = false;

	/** @var string 'admin' or 'front' — where the current command request came from */
	public $clientContext = 'admin';

	public function __construct() {
		parent::__construct();
		$this->set('shortcut', 'k');
		$this->set('shortcutMod', 'alt');
		$this->set('slashShortcut', 1);
		$this->set('frontend', 1);
		$this->set('maxResults', 8);
		$this->set('logCommands', 1);
		$this->set('shortcuts', '');
		$this->set('sources', self::$allSources);
	}

	/* ================================================================== boot */

	public function ready() {
		$user = $this->wire()->user;
		$page = $this->wire()->page;
		if(!$page || !$user->isLoggedin()) return;

		$input = $this->wire()->input;
		$isAdmin = $page->template == 'admin';

		if($isAdmin && $input->get(self::ENDPOINT) !== null) {
			$this->respond();
			return;
		}

		if(!$this->allowed()) return;
		if($input->get('modal') || $this->wire()->config->ajax) return; // never inside modal iframes or ajax requests

		if($isAdmin) {
			$contextId = 0;
			if($this->processName($page) === 'ProcessPageEdit') $contextId = (int) $input->get('id');
			$config = $this->wire()->config;
			$config->styles->add($this->assetUrl('NativeCommand.css'));
			$config->scripts->add($this->assetUrl('NativeCommand.js'));
			$config->js('NativeCommand', $this->clientConfig($contextId, 'admin'));
		} else if($this->frontend) {
			$this->addHookAfter('Page::render', $this, 'hookFrontendRender');
		}
	}

	/** Modifier options for the palette shortcut */
	protected function shortcutMods() {
		return [
			'alt' => 'Alt / ⌥ Option',
			'mod' => 'Ctrl / ⌘ Cmd',
			'modShift' => 'Ctrl / ⌘ Cmd + Shift',
			'modAlt' => 'Ctrl / ⌘ Cmd + Alt / ⌥',
		];
	}

	protected function shortcutMod() {
		$mod = (string) $this->shortcutMod;
		return array_key_exists($mod, $this->shortcutMods()) ? $mod : 'alt';
	}

	protected function shortcutKey() {
		$key = strtolower(substr(trim((string) $this->shortcut), 0, 1));
		return ($key !== '' && ctype_alnum($key)) ? $key : 'k';
	}

	/** Human readable shortcut, e.g. “Alt/⌥+K” */
	public function shortcutLabel() {
		$prefix = [
			'alt' => 'Alt/⌥+',
			'mod' => 'Ctrl/⌘+',
			'modShift' => 'Ctrl/⌘+Shift+',
			'modAlt' => 'Ctrl/⌘+Alt/⌥+',
		];
		return $prefix[$this->shortcutMod()] . strtoupper($this->shortcutKey());
	}

	protected function allowed() {
		$user = $this->wire()->user;
		return $user->isLoggedin() && ($user->isSuperuser() || $user->hasPermission('page-edit'));
	}

	/**
	 * Does the current user have the given permission? ('superuser' = superuser only)
	 *
	 * @param string $permission
	 * @return bool
	 */
	public function can($permission) {
		$user = $this->wire()->user;
		if($user->isSuperuser()) return true;
		if($permission === 'superuser') return false;
		return $user->hasPermission($permission);
	}

	protected function source($name) {
		return in_array($name, (array) $this->sources, true);
	}

	protected function processName(Page $p) {
		$process = $p->get('process');
		if(is_object($process)) return $process->className();
		return (string) $process;
	}

	protected function assetUrl($file) {
		$config = $this->wire()->config;
		$path = $config->paths($this) . $file;
		$v = is_file($path) ? filemtime($path) : time();
		return $config->urls($this) . $file . '?v=' . $v;
	}

	/* ============================================================== frontend */

	/** @var bool Front-end assets already injected in this request */
	protected $injected = false;

	public function hookFrontendRender(HookEvent $event) {
		/** @var Page $page */
		$page = $event->object;
		if($this->injected || $page->template == 'admin') return;
		if($page->id !== $this->wire()->page->id || $this->wire()->config->ajax) return; // only the main page, never partial/ajax renders
		$contentType = (string) $page->template->contentType;
		if($contentType !== '' && stripos($contentType, 'html') === false) return;

		$html = (string) $event->return;
		$pos = strripos($html, '</body>');
		if($pos === false) return;

		$cfg = $this->clientConfig((int) $page->id, 'front');
		$json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$inject =
			"\n<link rel='stylesheet' href='" . $this->assetUrl('NativeCommand.css') . "'>" .
			"\n<script type='application/json' id='nc-config'>$json</script>" .
			"\n<script src='" . $this->assetUrl('NativeCommand.js') . "' defer></script>\n";

		$event->return = substr($html, 0, $pos) . $inject . substr($html, $pos);
		$this->injected = true;
	}

	/* ========================================================= client config */

	protected function clientConfig($contextId, $ctx) {
		$config = $this->wire()->config;
		$csrf = $this->wire()->session->CSRF;
		$key = $this->shortcutKey();
		$mod = $this->shortcutMod();
		$keyLabel = $this->shortcutLabel();

		$scopes = [];
		if($this->source('commands')) $scopes[] = ['p' => '>', 'l' => $this->_('Commands')];
		if($this->source('pages')) $scopes[] = ['p' => '/', 'l' => $this->_('Pages')];
		if($this->source('templates') && $this->can('template-admin')) $scopes[] = ['p' => '#', 'l' => $this->_('Templates')];
		if($this->source('fields') && $this->can('field-admin')) $scopes[] = ['p' => '$', 'l' => $this->_('Fields')];
		if($this->source('modules') && $this->can('module-admin')) $scopes[] = ['p' => ':', 'l' => $this->_('Modules')];
		if($this->source('users') && $this->can('user-admin')) $scopes[] = ['p' => '@', 'l' => $this->_('Users')];

		return $this->plain([
			'scopes' => $scopes,
			'endpoint' => $config->urls->admin . '?' . self::ENDPOINT . '=',
			'key' => $key,
			'mod' => $mod,
			'keyLabel' => $keyLabel,
			'slash' => (bool) $this->slashShortcut,
			'max' => max(3, (int) $this->maxResults),
			'uid' => (int) $this->wire()->user->id,
			'pageId' => (int) $contextId,
			'ctx' => $ctx,
			'csrf' => ['n' => $csrf->getTokenName(), 'v' => $csrf->getTokenValue()],
			'labels' => [
				'title' => $this->_('Command palette'),
				'placeholder' => $this->_('What do you want to do? Type a command, search, or ? for help'),
				'tipNoPage' => sprintf($this->_('Tip: open a page (or its editor) and press %s to publish, rename, duplicate, move or trash it from here.'), $keyLabel),
				'tipPage' => $this->_('Commands for the page you are on are listed first — just press Enter.'),
				'helpBtn' => $this->_('What can I do?'),
				'helpTitle' => $this->_('How it works'),
				'help' => [
					[$this->_('Run a command'), $this->_('Pick it and press Enter. If it needs a value (a title, a page…) you are asked for it, risky ones ask for confirmation.')],
					[$this->_('Page commands'), sprintf($this->_('Open a page on the site or in the editor, then press %s — “This page” lists what you can do with it.'), $keyLabel)],
					[$this->_('Open / close'), sprintf($this->_('%s anywhere (change it in the module settings).'), $keyLabel)],
					[$this->_('Search'), $this->_('Just type. Narrow down with the buttons or prefixes: > commands, / pages, # templates, $ fields, : modules, @ users.')],
					[$this->_('Keys'), $this->_('↑↓ select · Enter run · Backspace step back · Esc back/close · Alt+P pin · Ctrl/⌘+Enter new tab · Shift+Enter view page')],
					[$this->_('Help'), $this->_('Type ? at any time to see this again.')],
				],
				'allCommands' => $this->_('All available commands'),
				'undo' => $this->_('Undo'),
				'copyId' => $this->_('Click to copy ID'),
				'pickHint' => $this->_('Type words or a selector, e.g. template=event, date<today'),
				'pickContinue' => $this->_('Continue with %d selected pages'),
				'pickFound' => $this->_('%1$d found · Enter on a page toggles it · Ctrl/⌘+A all/none'),
				'pickMore' => $this->_('Only the first %d can be processed at once — refine the selector'),
				'pickNone' => $this->_('Select at least one page.'),
				'pickCount' => $this->_('%d pages'),
				'copied' => $this->_('Copied: %s'),
				'current' => $this->_('current'),
				'keepEmpty' => $this->_('Enter = save · empty = clear'),
				'pageOnly' => $this->_('needs an open page'),
				'hint' => $this->_('↑↓ select · Enter run/open · Ctrl/⌘+Enter new tab · Alt+P pin · Esc back/close'),
				'empty' => $this->_('No results'),
				'loading' => $this->_('Searching…'),
				'error' => $this->_('Request failed — are you still logged in?'),
				'enter' => $this->_('Press Enter to continue'),
				'typeToSearch' => $this->_('Type to search…'),
				'typeNew' => $this->_('Type to start something new…'),
				'editorTitle' => $this->_('Edit: %s'),
				'editorSaved' => $this->_('Saved: %s'),
				'close' => $this->_('Close'),
				'confirm' => $this->_('Yes, run it'),
				'cancel' => $this->_('Cancel'),
				'running' => $this->_('Running…'),
				'back' => $this->_('Back to search'),
				'open' => $this->_('Open'),
				'pin' => $this->_('Pin'),
				'unpin' => $this->_('Unpin'),
				'unsaved' => $this->_('This page has unsaved changes in the editor — they will be lost.'),
				'required' => $this->_('This value is required.'),
				'groups' => [
					'pinned' => $this->_('Pinned'),
					'recent' => $this->_('Recently used'),
					'page' => $this->_('This page'),
					'edited' => $this->_('Recently edited by you'),
					'commands' => $this->_('Commands'),
					'shortcuts' => $this->_('Shortcuts'),
					'actions' => $this->_('Go to'),
					'pages' => $this->_('Pages'),
					'nav' => $this->_('Admin'),
					'templates' => $this->_('Templates'),
					'fields' => $this->_('Fields'),
					'modules' => $this->_('Modules'),
					'users' => $this->_('Users'),
					'custom' => $this->_('More'),
				],
			],
		]);
	}

	/**
	 * Decode HTML entities in all strings (translated labels are entity-encoded,
	 * while the client renders everything with textContent)
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	protected function plain($value) {
		if(is_string($value)) return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if(is_array($value)) {
			foreach($value as $k => $v) $value[$k] = $this->plain($v);
		}
		return $value;
	}

	/* ============================================================== endpoint */

	protected function respond() {
		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;
		$mode = (string) $input->get(self::ENDPOINT);

		if(!$this->allowed()) $this->jsonOut(['ok' => false, 'message' => $this->_('Not allowed')], 403);

		if($mode === 'init') {
			$page = $this->loadContextPage((int) $input->get('page'));
			$this->jsonOut(['ok' => true, 'items' => $this->getStaticItems($page)]);
		}

		if($mode === 'q') {
			$q = trim($sanitizer->text((string) $input->get('q'), ['maxLength' => 300, 'stripTags' => false]));
			$scope = $sanitizer->name((string) $input->get('scope'));
			if(!in_array($scope, self::$allSources, true)) $scope = '';
			$this->jsonOut(['ok' => true, 'items' => $q === '' ? [] : $this->search($q, $scope)]);
		}

		if($mode === 'run') $this->runCommand();
		if($mode === 'opts') $this->choiceOptions();
		if($mode === 'find') {
			$q = trim($sanitizer->text((string) $input->get('q'), ['maxLength' => 500, 'stripTags' => false]));
			$this->jsonOut($this->findForBulk($q));
		}

		$this->jsonOut(['ok' => false, 'message' => 'Unknown request'], 400);
	}

	protected function jsonOut(array $data, $code = 200) {
		$data = $this->plain($data);
		http_response_code($code);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
		header('X-Robots-Tag: noindex');
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
		exit;
	}

	/**
	 * Load a page usable as command context (exists, not admin, not trash, visible to user)
	 *
	 * @param int $id
	 * @return Page|null
	 */
	protected function loadContextPage($id) {
		if($id < 1) return null;
		$p = $this->wire()->pages->get((int) $id);
		if(!$p->id || $p->template == 'admin' || $p->isTrash()) return null;
		if(!$p->editable() && !$p->viewable(false)) return null;
		return $p;
	}

	/* ======================================================= command registry */

	/**
	 * Register a command
	 *
	 * @param string $id Unique id, e.g. 'invoice.new'
	 * @param array $def See README for keys
	 * @return $this
	 * @throws WireException
	 */
	public function addCommand($id, array $def) {
		$id = $this->wire()->sanitizer->name((string) $id);
		if($id === '') throw new WireException('NativeCommand: invalid command id');
		$def = array_merge([
			'title' => $id,
			'summary' => '',
			'keywords' => '',
			'group' => '',
			'context' => 'global',   // 'global' or 'page'
			'permission' => '',      // permission name, 'superuser', or ''
			'available' => null,     // callable(?Page $page, NativeCommand $nc): bool
			'args' => [],
			'confirm' => '',         // confirmation text, '' = none
			'mutates' => true,       // changes data? (logged, unsaved-changes guard)
			'handler' => null,       // callable(array $args, ?Page $page, NativeCommand $nc): array|string
		], $def);
		if(!is_callable($def['handler'])) throw new WireException("NativeCommand: handler for '$id' is not callable");
		if(!in_array($def['context'], ['global', 'page'], true)) $def['context'] = 'global';

		$args = [];
		foreach((array) $def['args'] as $spec) {
			if(empty($spec['name'])) continue;
			$spec = array_merge(['type' => 'text', 'label' => $spec['name'], 'required' => true, 'default' => null, 'options' => null], $spec);
			if($spec['type'] === 'choice' && !is_callable($spec['options'])) $spec['type'] = 'text';
			if(!in_array($spec['type'], self::$argTypes, true)) $spec['type'] = 'text';
			$args[] = $spec;
		}
		$def['args'] = $args;
		$this->commands[$id] = $def;
		return $this;
	}

	/**
	 * Remove a command (e.g. to disable a built-in one)
	 *
	 * @param string $id
	 * @return $this
	 */
	public function removeCommand($id) {
		$this->getCommands();
		unset($this->commands[$id]);
		return $this;
	}

	/**
	 * Hook after this to register your own commands with addCommand()
	 *
	 */
	public function ___registerCommands() {
		$this->registerBuiltins();
	}

	/**
	 * @return array All registered commands
	 */
	public function getCommands() {
		if(!$this->commandsLoaded) {
			$this->commandsLoaded = true;
			$this->registerCommands();
		}
		return $this->commands;
	}

	protected function commandAvailable(array $def, ?Page $page) {
		if($def['context'] === 'page' && !$page) return false;
		if($def['permission'] && !$this->can($def['permission'])) return false;
		if(is_callable($def['available'])) {
			try {
				return (bool) call_user_func($def['available'], $page, $this);
			} catch(\Throwable $e) {
				return false;
			}
		}
		return true;
	}

	protected function commandItem($id, array $def, ?Page $page) {
		$args = [];
		foreach($def['args'] as $spec) {
			$default = $spec['type'] === 'choice' ? null : $spec['default'];
			if(is_callable($default)) {
				try { $default = call_user_func($default, $page, $this); } catch(\Throwable $e) { $default = null; }
			}
			$args[] = [
				'n' => $spec['name'],
				't' => $spec['type'],
				'l' => (string) $spec['label'],
				'r' => (bool) $spec['required'],
				'd' => is_scalar($default) ? (string) $default : '',
			];
		}
		$group = $def['group'] ? $def['group'] : ($def['context'] === 'page' ? 'page' : 'commands');
		foreach(['summary', 'confirm'] as $key) {
			if(is_callable($def[$key]) && !is_string($def[$key])) {
				try { $def[$key] = (string) call_user_func($def[$key], $page, $this); } catch(\Throwable $e) { $def[$key] = ''; }
			}
		}
		return [
			't' => (string) $def['title'],
			's' => (string) ($def['summary'] ?: ($def['context'] === 'page' && $page ? $page->get('title|name') : '')),
			'g' => $group,
			'c' => $id,
			'a' => $args,
			'cf' => (string) $def['confirm'],
			'm' => (bool) $def['mutates'],
			'pc' => $def['context'] === 'page',
			'k' => (string) $def['keywords'],
		];
	}

	/**
	 * Options for a 'choice' argument (list to pick from, or free text with a default)
	 *
	 */
	protected function choiceOptions() {
		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;
		$commands = $this->getCommands();
		$id = $sanitizer->name((string) $input->get('cmd'));
		if(!isset($commands[$id])) $this->jsonOut(['ok' => false, 'message' => $this->_('Unknown command')], 404);
		$def = $commands[$id];
		$page = $this->loadContextPage((int) $input->get('page'));
		if(!$this->commandAvailable($def, $page)) $this->jsonOut(['ok' => false, 'message' => $this->_('Not available here.')], 403);

		$argName = $sanitizer->name((string) $input->get('arg'));
		$raw = $input->get('a');
		if(!is_array($raw)) $raw = [];
		$prev = [];
		$spec = null;
		foreach($def['args'] as $s) {
			if($s['name'] === $argName) { $spec = $s; break; }
			$prev[$s['name']] = $this->sanitizeArg($s, $raw[$s['name']] ?? null);
		}
		if(!$spec || $spec['type'] !== 'choice') $this->jsonOut(['ok' => false, 'message' => 'Bad argument'], 400);

		$q = trim($sanitizer->text((string) $input->get('q'), ['maxLength' => 80]));
		try {
			$options = call_user_func($spec['options'], $page, $prev, $q, $this);
		} catch(\Throwable $e) {
			$this->jsonOut(['ok' => false, 'message' => $e instanceof WireException ? $e->getMessage() : $this->_('Could not load options.')], 400);
		}

		if($options === null) {
			$default = $spec['default'];
			if(is_callable($default)) {
				try { $default = call_user_func($default, $page, $prev, $this); } catch(\Throwable $e) { $default = ''; }
			}
			$this->jsonOut(['ok' => true, 'mode' => 'text', 'd' => is_scalar($default) ? (string) $default : '']);
		}

		$items = [];
		foreach(array_slice((array) $options, 0, 60) as $o) {
			if(!is_array($o) || !isset($o['value'])) continue;
			$items[] = [
				'id' => (string) $o['value'],
				't' => (string) ($o['label'] ?? $o['value']),
				's' => (string) ($o['summary'] ?? ''),
				'on' => !empty($o['selected']),
				'tpl' => isset($o['tpl']) ? (string) $o['tpl'] : '',
				'open' => isset($o['open']) ? (string) $o['open'] : '',
			];
		}
		$this->jsonOut(['ok' => true, 'mode' => 'list', 'items' => $items]);
	}

	protected function runCommand() {
		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;
		$session = $this->wire()->session;

		if($input->requestMethod() !== 'POST') {
			$this->jsonOut(['ok' => false, 'message' => 'POST required'], 405);
		}
		if(!$session->CSRF->hasValidToken()) {
			$this->jsonOut(['ok' => false, 'message' => $this->_('Your session token expired — reload the page and try again.')], 403);
		}

		$this->clientContext = $input->post('ctx') === 'front' ? 'front' : 'admin';
		$id = $sanitizer->name((string) $input->post('cmd'));
		$commands = $this->getCommands();
		if(!isset($commands[$id])) {
			$this->jsonOut(['ok' => false, 'message' => $this->_('Unknown command')], 404);
		}
		$def = $commands[$id];
		$page = $this->loadContextPage((int) $input->post('page'));

		if(!$this->commandAvailable($def, $page)) {
			$this->jsonOut(['ok' => false, 'message' => $this->_('This command is not available here, or you do not have permission.')], 403);
		}

		$raw = $input->post('args');
		if(!is_array($raw)) $raw = [];
		$args = [];
		foreach($def['args'] as $spec) {
			$value = $this->sanitizeArg($spec, $raw[$spec['name']] ?? null);
			if($value === null && $spec['required']) {
				$this->jsonOut(['ok' => false, 'message' => sprintf($this->_('Missing or invalid value: %s'), $spec['label'])], 400);
			}
			$args[$spec['name']] = $value;
		}

		try {
			$result = call_user_func($def['handler'], $args, $page, $this);
		} catch(WireException $e) {
			$this->jsonOut(['ok' => false, 'message' => $e->getMessage()], 400);
		} catch(\Throwable $e) {
			$this->wire()->log->error("NativeCommand '$id': " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
			$this->jsonOut(['ok' => false, 'message' => $this->_('The command failed. Details were written to the errors log.')], 500);
		}

		if(!is_array($result)) $result = ['message' => (string) $result];

		if($def['mutates'] && $this->logCommands) {
			$this->wire()->log->save(self::LOG, sprintf(
				'%s ran %s%s',
				$this->wire()->user->name,
				$id,
				$page ? " on {$page->path} (id $page->id)" : ''
			));
		}

		$rows = [];
		foreach((array) ($result['rows'] ?? []) as $row) {
			if(!is_array($row)) continue;
			$row = array_values($row);
			$rows[] = [(string) ($row[0] ?? ''), (string) ($row[1] ?? '')];
		}

		$this->jsonOut([
			'ok' => true,
			'type' => ($result['type'] ?? 'success') === 'error' ? 'error' : 'success',
			'message' => (string) ($result['message'] ?? $this->_('Done.')),
			'rows' => $rows,
			'open' => (string) ($result['open'] ?? ''),
			'redirect' => (string) ($result['redirect'] ?? ''),
			'reload' => !empty($result['reload']),
			'undo' => $this->undoPushed,
		]);
	}

	/**
	 * @param array $spec
	 * @param mixed $value
	 * @return mixed|null Null when empty/invalid
	 */
	protected function sanitizeArg(array $spec, $value) {
		if(is_array($value) || $value === null) return null;
		$value = trim((string) $value);
		if($value === '') return null;

		switch($spec['type']) {
			case 'int':
				return is_numeric($value) ? (int) $value : null;
			case 'page':
				$p = $this->loadContextPage((int) $value);
				return $p ?: null;
			case 'template':
				if(!$this->can('template-admin')) return null;
				$t = $this->wire()->templates->get((int) $value);
				return $t instanceof Template ? $t : null;
			case 'field':
				if(!$this->can('field-admin')) return null;
				$f = $this->wire()->fields->get((int) $value);
				return $f instanceof Field ? $f : null;
			case 'pages':
				$ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', $value)))), 0, self::BULK_MAX);
				if(!$ids) return null;
				$list = $this->wire()->pages->newPageArray();
				foreach($this->wire()->pages->getById($ids) as $p) {
					if($p->template == 'admin' || $p->isTrash() || !$p->editable()) continue;
					$list->add($p);
				}
				return $list->count() ? $list : null;
			case 'choice':
				// field values: keep characters like “<” (e.g. “I <3 it”); fields and output are escaped as usual
				$text = $this->wire()->sanitizer->text($value, ['maxLength' => 16384, 'stripTags' => false]);
				return $text === '' ? null : $text;
			default:
				$text = $this->wire()->sanitizer->text($value, ['maxLength' => 1024, 'stripTags' => false]);
				return $text === '' ? null : $text;
		}
	}

	/* ====================================================== built-in commands */

	protected function addBuiltin($id, array $def) {
		if(!isset($this->commands[$id])) $this->addCommand($id, $def);
	}

	protected function registerBuiltins() {
		$rootId = (int) $this->wire()->config->rootPageID;

		/* ---------- this page ---------- */

		$this->addBuiltin('page.setField', [
			'title' => $this->_('Edit field…'),
			'summary' => $this->_('Any field — simple ones right here, rich text, images, repeaters… in their own editor'),
			'context' => 'page',
			'keywords' => 'set field value change update date price number image file text body',
			'args' => [
				[
					'name' => 'field',
					'type' => 'choice',
					'label' => $this->_('Field'),
					'options' => function(?Page $p, array $prev, $q) {
						return $this->fieldChoices($p, $q);
					},
				],
				[
					'name' => 'value',
					'type' => 'choice',
					'label' => $this->_('New value'),
					'required' => false,
					'options' => function(?Page $p, array $prev, $q) {
						$ref = $p ? $this->fieldRef($p, (string) $prev['field']) : null;
						return $ref ? $this->valueChoices($p, $ref['field'], $q) : [];
					},
					'default' => function(?Page $p, array $prev) {
						$ref = $p ? $this->fieldRef($p, (string) $prev['field']) : null;
						return $ref ? $this->editValue($p, $ref['field'], $ref['lang']) : '';
					},
				],
			],
			'available' => function(?Page $p) {
				return $p && $p->editable() && count($this->editableFields($p, false)) > 0;
			},
			'handler' => function(array $a, ?Page $p) {
				$ref = $this->fieldRef($p, (string) $a['field']);
				if(!$ref) throw new WireException($this->_('This field cannot be edited here.'));
				$this->applyFieldValue($p, $ref, (string) $a['value'], false);
				$label = $this->fieldLabel($p, $ref['field']) . ($ref['lang'] ? ' (' . $ref['lang']->get('title|name') . ')' : '');
				return ['message' => sprintf($this->_('%1$s: %2$s'), $label, $this->displayValue($p, $ref['field'], $ref['lang'])), 'reload' => true];
			},
		]);

		$this->addBuiltin('page.publish', [
			'title' => $this->_('Publish'),
			'summary' => $this->_('Make this page visible to visitors'),
			'context' => 'page',
			'keywords' => 'publish online live activate',
			'available' => function(?Page $p) {
				return $p && $p->isUnpublished() && $p->publishable();
			},
			'handler' => function(array $a, ?Page $p) {
				$missing = $this->missingRequired($p);
				if($missing) {
					return [
						'type' => 'error',
						'message' => sprintf($this->_('Not published — required fields are empty: %s'), implode(', ', $missing)),
						'open' => $p->editUrl(),
					];
				}
				$this->pushStatusUndo($p, $this->_('Publish'));
				$p->of(false);
				$p->removeStatus(Page::statusUnpublished);
				$p->save();
				return ['message' => sprintf($this->_('Published: %s'), $p->get('title|name')), 'reload' => true];
			},
		]);

		$this->addBuiltin('page.unpublish', [
			'title' => $this->_('Unpublish'),
			'summary' => $this->_('Take this page offline (keeps the content)'),
			'context' => 'page',
			'keywords' => 'unpublish offline draft',
			'confirm' => $this->_('Visitors will no longer see this page.'),
			'available' => function(?Page $p) use ($rootId) {
				return $p && $p->id !== $rootId && !$p->isUnpublished() && !$p->hasStatus(Page::statusSystem) && $p->publishable();
			},
			'handler' => function(array $a, ?Page $p) {
				$this->pushStatusUndo($p, $this->_('Unpublish'));
				$p->of(false);
				$p->addStatus(Page::statusUnpublished);
				$p->save();
				return ['message' => sprintf($this->_('Unpublished: %s'), $p->get('title|name')), 'reload' => true];
			},
		]);

		$this->addBuiltin('page.hide', [
			'title' => $this->_('Hide from menus and lists'),
			'summary' => $this->_('Page stays online but disappears from navigation'),
			'context' => 'page',
			'keywords' => 'hide hidden navigation menu',
			'available' => function(?Page $p) use ($rootId) {
				return $p && $p->id !== $rootId && !$p->isHidden() && $p->editable();
			},
			'handler' => function(array $a, ?Page $p) {
				$this->pushStatusUndo($p, $this->_('Hide'));
				$p->of(false);
				$p->addStatus(Page::statusHidden);
				$p->save();
				return ['message' => $this->_('Page is now hidden.'), 'reload' => true];
			},
		]);

		$this->addBuiltin('page.unhide', [
			'title' => $this->_('Show in menus and lists'),
			'summary' => $this->_('Show this page in navigation again'),
			'context' => 'page',
			'keywords' => 'unhide visible navigation',
			'available' => function(?Page $p) {
				return $p && $p->isHidden() && $p->editable();
			},
			'handler' => function(array $a, ?Page $p) {
				$this->pushStatusUndo($p, $this->_('Show'));
				$p->of(false);
				$p->removeStatus(Page::statusHidden);
				$p->save();
				return ['message' => $this->_('Page is visible in menus and lists again.'), 'reload' => true];
			},
		]);

		$this->addBuiltin('page.rename', [
			'title' => $this->_('Change title…'),
			'summary' => $this->_('Rename the page without opening the editor'),
			'context' => 'page',
			'keywords' => 'rename title heading name',
			'args' => [[
				'name' => 'title',
				'type' => 'text',
				'label' => $this->_('New title'),
				'default' => function(?Page $p) { return $p ? (string) $p->getUnformatted('title') : ''; },
			]],
			'available' => function(?Page $p) {
				return $p && $p->template->hasField('title') && $p->editable('title');
			},
			'handler' => function(array $a, ?Page $p) {
				$f = $this->wire()->fields->get('title');
				$this->pushUndo('fields', $p, sprintf($this->_('Title of “%s”'), $p->get('title|name')), [
					'fields' => ['title' => $this->snapshot($p, $f)],
				]);
				$p->of(false);
				$p->set('title', $a['title']);
				$p->save('title');
				return ['message' => sprintf($this->_('Title changed to “%s”.'), $a['title']), 'reload' => true];
			},
		]);

		$this->addBuiltin('page.info', [
			'title' => $this->_('Page info & references'),
			'summary' => $this->_('Who created/changed it, URLs, status — and which pages point to it'),
			'context' => 'page',
			'keywords' => 'info details references links used where usage who',
			'mutates' => false,
			'handler' => function(array $a, ?Page $p) {
				return $this->pageInfo($p);
			},
		]);

		$this->addBuiltin('page.addChild', [
			'title' => $this->_('Add child page…'),
			'summary' => $this->_('Create a new page under this one — just type the title'),
			'context' => 'page',
			'keywords' => 'new create add child subpage',
			'args' => [['name' => 'title', 'type' => 'text', 'label' => $this->_('Title of the new page')]],
			'available' => function(?Page $p) {
				return $p && !$p->template->noChildren && $p->addable();
			},
			'handler' => function(array $a, ?Page $p) {
				return $this->createChild($p, $a['title']);
			},
		]);

		$this->addBuiltin('page.clone', [
			'title' => $this->_('Duplicate'),
			'summary' => $this->_('Creates an unpublished copy next to this page'),
			'context' => 'page',
			'keywords' => 'clone copy duplicate',
			'available' => function(?Page $p) use ($rootId) {
				if(!$p || $p->id === $rootId || !$p->parent->id) return false;
				if($this->wire()->permissions->get('page-clone')->id && !$this->can('page-clone')) return false;
				return $p->editable() && $p->parent->addable();
			},
			'handler' => function(array $a, ?Page $p) {
				$pages = $this->wire()->pages;
				$copy = $pages->clone($p, $p->parent, false, ['set' => ['status' => $p->status | Page::statusUnpublished]]);
				if(!$copy || !$copy->id) throw new WireException($this->_('Could not duplicate the page.'));
				if($copy->template->hasField('title')) {
					$copy->of(false);
					$copy->set('title', trim($p->getUnformatted('title') . ' ' . $this->_('(copy)')));
					$copy->save('title');
				}
				$this->pushUndo('created', $copy, sprintf($this->_('Duplicate of “%s”'), $p->get('title|name')), ['back' => $p->id]);
				return ['message' => $this->_('Copy created.'), 'redirect' => $copy->editUrl()];
			},
		]);

		$this->addBuiltin('page.move', [
			'title' => $this->_('Move under…'),
			'summary' => $this->_('Choose a new parent page'),
			'context' => 'page',
			'keywords' => 'move parent relocate',
			'args' => [['name' => 'parent', 'type' => 'page', 'label' => $this->_('New parent page')]],
			'confirm' => function(?Page $p) {
				return trim($this->_('The page URL will change.') . ' ' . $this->referencesNote($p));
			},
			'available' => function(?Page $p) use ($rootId) {
				return $p && $p->id !== $rootId && $p->editable() && $p->moveable();
			},
			'handler' => function(array $a, ?Page $p) {
				/** @var Page $parent */
				$parent = $a['parent'];
				if($parent->id === $p->id || $parent->parents->has($p)) {
					throw new WireException($this->_('A page cannot be moved under itself.'));
				}
				if($parent->id === $p->parent->id) {
					return ['message' => $this->_('The page is already there.')];
				}
				if(!$p->moveable($parent)) throw new WireException($this->_('You are not allowed to move the page there.'));
				$renamed = $this->movePage($p, $parent);
				return [
					'message' => $renamed
						? sprintf($this->_('Moved to %s (renamed — a page with the same name already existed there)'), $p->path)
						: sprintf($this->_('Moved to %s'), $p->path),
					'redirect' => $this->clientContext === 'front' ? $p->url : '',
					'reload' => $this->clientContext !== 'front',
				];
			},
		]);

		$this->addBuiltin('page.trash', [
			'title' => $this->_('Move to trash'),
			'summary' => $this->_('Trash this page and its children (restorable)'),
			'context' => 'page',
			'keywords' => 'delete trash remove bin',
			'confirm' => function(?Page $p) {
				return trim($this->_('The page and its children go to the trash.') . ' ' . $this->referencesNote($p));
			},
			'available' => function(?Page $p) {
				return $p && $p->trashable();
			},
			'handler' => function(array $a, ?Page $p) {
				$parent = $p->parent;
				$title = $p->get('title|name');
				$this->pushUndo('trash', $p, sprintf($this->_('Trash of “%s”'), $title), $this->trashUndoData($p));
				$this->wire()->pages->trash($p);
				return [
					'message' => sprintf($this->_('“%s” moved to trash.'), $title),
					'redirect' => $this->clientContext === 'front'
						? $parent->url
						: $this->wire()->config->urls->admin . 'page/?open=' . $parent->id,
				];
			},
		]);

		/* ---------- global ---------- */

		$this->addBuiltin('undo.last', [
			'title' => $this->_('Undo'),
			'summary' => function() {
				$stack = $this->getUndoStack();
				return $stack ? sprintf($this->_('Undo: %s'), $stack[0]['label']) : '';
			},
			'keywords' => 'undo revert back restore',
			'available' => function() {
				return count($this->getUndoStack()) > 0;
			},
			'handler' => function() {
				return $this->undoLast();
			},
		]);

		$this->addBuiltin('page.addUnder', [
			'title' => $this->_('New page under…'),
			'summary' => $this->_('Pick a parent, type a title — the page is created'),
			'keywords' => 'new create add page',
			'permission' => 'page-edit',
			'args' => [
				['name' => 'parent', 'type' => 'page', 'label' => $this->_('Parent page')],
				['name' => 'title', 'type' => 'text', 'label' => $this->_('Title of the new page')],
			],
			'handler' => function(array $a) {
				return $this->createChild($a['parent'], $a['title']);
			},
		]);

		$this->addBuiltin('template.addField', [
			'title' => $this->_('Add field to template…'),
			'summary' => $this->_('Pick a field and a template — no Setup screens'),
			'keywords' => 'field template fieldgroup assign',
			'permission' => 'template-admin',
			'args' => [
				['name' => 'field', 'type' => 'field', 'label' => $this->_('Field')],
				['name' => 'template', 'type' => 'template', 'label' => $this->_('Template')],
			],
			'available' => function() { return $this->can('field-admin'); },
			'handler' => function(array $a) {
				/** @var Field $f */
				/** @var Template $t */
				$f = $a['field'];
				$t = $a['template'];
				if(($t->flags & Template::flagSystem) && !$this->wire()->user->isSuperuser()) {
					throw new WireException($this->_('System templates can only be changed by superusers.'));
				}
				if($t->fieldgroup->hasField($f)) {
					return ['message' => sprintf($this->_('“%1$s” already has field “%2$s”.'), $t->name, $f->name), 'type' => 'error'];
				}
				$t->fieldgroup->add($f);
				$t->fieldgroup->save();
				return [
					'message' => sprintf($this->_('Field “%1$s” added to template “%2$s” (at the end).'), $f->name, $t->name),
					'open' => $this->wire()->config->urls->admin . 'setup/template/edit?id=' . $t->id,
				];
			},
		]);

		$this->addBuiltin('cache.clear', [
			'title' => $this->_('Clear caches'),
			'summary' => $this->_('Clear WireCache and ProCache'),
			'keywords' => 'cache clear flush procache wirecache',
			'permission' => 'superuser',
			'confirm' => $this->_('Clears time-based WireCache entries (permanent ones are kept) and ProCache if installed.'),
			'handler' => function() {
				$rows = [];
				$n = $this->wire()->cache->expireAll(); // keeps expireNever/expireReserved data
				$rows[] = ['WireCache', is_int($n) ? sprintf($this->_('%d entries removed'), $n) : $this->_('cleared')];
				$modules = $this->wire()->modules;
				if($modules->isInstalled('ProCache')) {
					$pc = $modules->get('ProCache');
					if($pc && method_exists($pc, 'clearAll')) {
						$pc->clearAll();
						$rows[] = ['ProCache', $this->_('cleared')];
					}
				}
				return ['message' => $this->_('Caches cleared.'), 'rows' => $rows];
			},
		]);

		$this->addBuiltin('modules.refresh', [
			'title' => $this->_('Refresh modules'),
			'summary' => $this->_('Detect new or updated modules'),
			'keywords' => 'modules refresh reload detect new',
			'permission' => 'module-admin',
			'handler' => function() {
				$modules = $this->wire()->modules;
				$modules->refresh();
				$new = array_keys((array) $modules->getInstallable());
				return [
					'message' => $this->_('Module cache refreshed.'),
					'rows' => $new ? [[$this->_('Not installed yet'), implode(', ', array_slice($new, 0, 12))]] : [],
					'open' => $new ? $this->wire()->config->urls->admin . 'module/' : '',
				];
			},
		]);

		$this->addBuiltin('logs.errors', [
			'title' => $this->_('Latest errors'),
			'summary' => $this->_('Show the last entries of the errors/exceptions logs'),
			'keywords' => 'errors exceptions log debug',
			'permission' => 'logs-view',
			'mutates' => false,
			'handler' => function() {
				$log = $this->wire()->log;
				$rows = [];
				foreach(['errors', 'exceptions'] as $name) {
					foreach($log->getEntries($name, ['limit' => 5]) as $e) {
						$text = isset($e['text']) ? (string) $e['text'] : '';
						if(mb_strlen($text) > 160) $text = mb_substr($text, 0, 160) . '…';
						$rows[] = [($e['date'] ?? '') . ' · ' . $name, $text];
					}
				}
				usort($rows, function($x, $y) { return strcmp($y[0], $x[0]); });
				return [
					'message' => $rows ? $this->_('Latest entries from errors and exceptions logs') : $this->_('No errors logged. 🎉'),
					'rows' => array_slice($rows, 0, 8),
					'open' => $this->wire()->config->urls->admin . 'setup/logs/view/errors/',
				];
			},
		]);

		$this->addBuiltin('system.info', [
			'title' => $this->_('System info'),
			'summary' => $this->_('ProcessWire, PHP, database, debug mode, disk space'),
			'keywords' => 'system info version php mysql status',
			'permission' => 'superuser',
			'mutates' => false,
			'handler' => function() {
				$config = $this->wire()->config;
				$rows = [
					['ProcessWire', $config->version],
					['PHP', PHP_VERSION . ' · ' . ini_get('memory_limit')],
				];
				try {
					$pdo = $this->wire()->database;
					$ver = method_exists($pdo, 'getVersion') ? $pdo->getVersion() : $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
					$rows[] = [$this->_('Database'), (string) $ver];
				} catch(\Throwable $e) { /* not critical */ }
				$rows[] = [$this->_('Debug mode'), $config->debug ? $this->_('ON') : $this->_('off')];
				$rows[] = [$this->_('Pages'), (string) $this->wire()->pages->count('include=all')];
				$rows[] = [$this->_('Templates / fields'), $this->wire()->templates->getAll()->count() . ' / ' . $this->wire()->fields->getAll()->count()];
				$theme = $this->wire()->adminTheme;
				if($theme) $rows[] = [$this->_('Admin theme'), $theme->className()];
				if(function_exists('disk_free_space')) {
					$free = @disk_free_space($config->paths->assets);
					if($free !== false) $rows[] = [$this->_('Free disk space'), wireBytesStr($free)];
				}
				return ['message' => $this->_('System overview'), 'rows' => $rows];
			},
		]);

		$this->registerBulkCommands($rootId);
		$this->registerShortcutCommands();
	}

	/* ===================================================== bulk commands */

	protected function pagesArg() {
		return [
			'name' => 'pages',
			'type' => 'pages',
			'label' => $this->_('Pages'),
		];
	}

	/**
	 * Run an action over many pages: per-page check, one combined undo, summary
	 *
	 * @param PageArray $list
	 * @param string $title
	 * @param callable $check function(Page $p): string — '' = ok, otherwise reason to skip
	 * @param callable $apply function(Page $p)
	 * @return array
	 */
	public function bulkRun(PageArray $list, $title, callable $check, callable $apply) {
		$pages = $this->wire()->pages;
		$done = 0;
		$skipped = [];
		$this->beginUndoBatch();
		try {
			foreach($list as $p) {
				$name = $p->get('title|name') . ' #' . $p->id;
				$reason = (string) $check($p);
				if($reason !== '') {
					$skipped[] = [$name, $reason];
					continue;
				}
				try {
					$apply($p);
					$done++;
				} catch(\Throwable $e) {
					$skipped[] = [$name, $e instanceof WireException ? $e->getMessage() : $this->_('error (see logs)')];
					if(!$e instanceof WireException) $this->wire()->log->error('NativeCommand bulk: ' . $e->getMessage());
				}
				if(($done + count($skipped)) % 50 === 0) $pages->uncacheAll();
			}
		} finally {
			$this->endUndoBatch(sprintf($this->_('%1$s (%2$d pages)'), $title, $done));
		}
		$rows = [[$this->_('Done'), (string) $done], [$this->_('Skipped'), (string) count($skipped)]];
		foreach(array_slice($skipped, 0, 10) as $row) $rows[] = $row;
		if(count($skipped) > 10) $rows[] = ['…', sprintf($this->_('%d more'), count($skipped) - 10)];
		return [
			'message' => sprintf($this->_('%1$s: %2$d of %3$d pages'), $title, $done, $list->count()),
			'rows' => $rows,
			'type' => $done ? 'success' : 'error',
		];
	}

	protected function registerBulkCommands($rootId) {
		$no = $this->_('no permission');

		$this->addBuiltin('bulk.publish', [
			'title' => $this->_('Bulk: publish pages…'),
			'summary' => $this->_('Select pages, publish them all'),
			'keywords' => 'bulk batch mass many publish',
			'permission' => 'page-edit',
			'args' => [$this->pagesArg()],
			'confirm' => $this->_('All selected pages will be published.'),
			'handler' => function(array $a) use ($no) {
				return $this->bulkRun($a['pages'], $this->_('Publish'), function(Page $p) use ($no) {
					if(!$p->isUnpublished()) return $this->_('already published');
					if(!$p->publishable()) return $no;
					$missing = $this->missingRequired($p);
					return $missing ? sprintf($this->_('required fields empty: %s'), implode(', ', $missing)) : '';
				}, function(Page $p) {
					$this->pushStatusUndo($p, $this->_('Publish'));
					$p->of(false);
					$p->removeStatus(Page::statusUnpublished);
					$p->save();
				});
			},
		]);

		$this->addBuiltin('bulk.unpublish', [
			'title' => $this->_('Bulk: unpublish pages…'),
			'summary' => $this->_('Select pages, take them offline'),
			'keywords' => 'bulk batch mass many unpublish offline',
			'permission' => 'page-edit',
			'args' => [$this->pagesArg()],
			'confirm' => $this->_('Visitors will no longer see the selected pages.'),
			'handler' => function(array $a) use ($no, $rootId) {
				return $this->bulkRun($a['pages'], $this->_('Unpublish'), function(Page $p) use ($no, $rootId) {
					if($p->isUnpublished()) return $this->_('already unpublished');
					if($p->id === $rootId || $p->hasStatus(Page::statusSystem)) return $this->_('system page');
					return $p->publishable() ? '' : $no;
				}, function(Page $p) {
					$this->pushStatusUndo($p, $this->_('Unpublish'));
					$p->of(false);
					$p->addStatus(Page::statusUnpublished);
					$p->save();
				});
			},
		]);

		$this->addBuiltin('bulk.hide', [
			'title' => $this->_('Bulk: hide pages…'),
			'summary' => $this->_('Select pages, hide them from menus and lists'),
			'keywords' => 'bulk batch mass hide hidden',
			'permission' => 'page-edit',
			'args' => [$this->pagesArg()],
			'handler' => function(array $a) use ($rootId) {
				return $this->bulkRun($a['pages'], $this->_('Hide'), function(Page $p) use ($rootId) {
					if($p->id === $rootId) return $this->_('home page');
					return $p->isHidden() ? $this->_('already hidden') : '';
				}, function(Page $p) {
					$this->pushStatusUndo($p, $this->_('Hide'));
					$p->of(false);
					$p->addStatus(Page::statusHidden);
					$p->save();
				});
			},
		]);

		$this->addBuiltin('bulk.unhide', [
			'title' => $this->_('Bulk: show pages in menus…'),
			'summary' => $this->_('Select pages, make them visible in navigation'),
			'keywords' => 'bulk batch mass unhide show visible',
			'permission' => 'page-edit',
			'args' => [$this->pagesArg()],
			'handler' => function(array $a) {
				return $this->bulkRun($a['pages'], $this->_('Show'), function(Page $p) {
					return $p->isHidden() ? '' : $this->_('not hidden');
				}, function(Page $p) {
					$this->pushStatusUndo($p, $this->_('Show'));
					$p->of(false);
					$p->removeStatus(Page::statusHidden);
					$p->save();
				});
			},
		]);

		$this->addBuiltin('bulk.move', [
			'title' => $this->_('Bulk: move pages under…'),
			'summary' => $this->_('Select pages and a new parent'),
			'keywords' => 'bulk batch mass move parent',
			'permission' => 'page-edit',
			'args' => [$this->pagesArg(), ['name' => 'parent', 'type' => 'page', 'label' => $this->_('New parent page')]],
			'confirm' => $this->_('URLs of the selected pages will change.'),
			'handler' => function(array $a) use ($no) {
				/** @var Page $parent */
				$parent = $a['parent'];
				return $this->bulkRun($a['pages'], sprintf($this->_('Move under %s'), $parent->path), function(Page $p) use ($parent, $no) {
					if($p->parent->id === $parent->id) return $this->_('already there');
					if($p->id === $parent->id || $parent->parents->has($p)) return $this->_('cannot move under itself');
					return $p->moveable($parent) ? '' : $no;
				}, function(Page $p) use ($parent) {
					$this->movePage($p, $parent);
				});
			},
		]);

		$this->addBuiltin('bulk.trash', [
			'title' => $this->_('Bulk: move pages to trash…'),
			'summary' => $this->_('Select pages, trash them (restorable, undoable)'),
			'keywords' => 'bulk batch mass delete trash remove',
			'permission' => 'page-edit',
			'args' => [$this->pagesArg()],
			'confirm' => $this->_('The selected pages and their children go to the trash.'),
			'handler' => function(array $a) use ($no) {
				return $this->bulkRun($a['pages'], $this->_('Trash'), function(Page $p) use ($no) {
					if($p->isTrash()) return $this->_('already in trash');
					return $p->trashable() ? '' : $no;
				}, function(Page $p) {
					$this->pushUndo('trash', $p, sprintf($this->_('Trash of “%s”'), $p->get('title|name')), $this->trashUndoData($p));
					$this->wire()->pages->trash($p);
				});
			},
		]);

		$this->addBuiltin('bulk.setField', [
			'title' => $this->_('Bulk: set field…'),
			'summary' => $this->_('Select pages, set one field on all of them'),
			'keywords' => 'bulk batch mass set field value update',
			'permission' => 'page-edit',
			'args' => [
				$this->pagesArg(),
				[
					'name' => 'field',
					'type' => 'choice',
					'label' => $this->_('Field (common to the selected pages)'),
					'options' => function(?Page $p, array $prev, $q) {
						$list = $prev['pages'] ?? null;
						if(!$list instanceof PageArray || !$list->count()) return [];
						return $this->commonFieldChoices($list, $q);
					},
				],
				[
					'name' => 'value',
					'type' => 'choice',
					'label' => $this->_('New value (multi-value fields: added)'),
					'required' => false,
					'options' => function(?Page $p, array $prev, $q) {
						$first = $prev['pages'] instanceof PageArray ? $prev['pages']->first() : null;
						$ref = $first ? $this->fieldRef($first, (string) $prev['field']) : null;
						if(!$ref) return [];
						$choices = $this->valueChoices($first, $ref['field'], $q);
						if(is_array($choices)) {
							foreach($choices as $k => $c) { $choices[$k]['selected'] = false; $choices[$k]['summary'] = ''; }
						}
						return $choices;
					},
					'default' => '',
				],
			],
			'confirm' => $this->_('The value will be written to every selected page.'),
			'handler' => function(array $a) {
				$refName = (string) $a['field'];
				return $this->bulkRun($a['pages'], $this->_('Set field'), function(Page $p) use ($refName) {
					return $this->fieldRef($p, $refName) ? '' : $this->_('field not editable on this page');
				}, function(Page $p) use ($refName, $a) {
					$this->applyFieldValue($p, $this->fieldRef($p, $refName), (string) $a['value'], true);
				});
			},
		]);
	}

	/**
	 * Fields editable on all of the given pages (checked on up to 50 pages)
	 *
	 */
	protected function commonFieldChoices(PageArray $list, $q) {
		$common = null;
		$i = 0;
		foreach($list as $p) {
			if(++$i > 50) break;
			$names = array_keys($this->editableFields($p, true));
			$common = $common === null ? $names : array_values(array_intersect($common, $names));
			if(!$common) return [];
		}
		$first = $list->first();
		$out = [];
		foreach($this->fieldChoices($first, $q, true) as $choice) {
			$name = explode('@', $choice['value'])[0];
			if(!in_array($name, (array) $common, true)) continue;
			$choice['summary'] = $name;
			$out[] = $choice;
		}
		return $out;
	}

	/* ========================================================== shortcuts */

	/**
	 * Parse the “Shortcuts” setting
	 *
	 * Line format: Label | target | role1, role2 (optional)
	 * target: a URL (/processwire/…, https://…) or “new /parent/path/ template-name”
	 *
	 * @return array
	 */
	public function getShortcuts() {
		$out = [];
		$user = $this->wire()->user;
		$lines = preg_split('/\R/', (string) $this->shortcuts);
		foreach($lines as $n => $line) {
			$line = trim($line);
			if($line === '' || strpos($line, '#') === 0) continue;
			$parts = array_map('trim', explode('|', $line));
			if(count($parts) < 2 || $parts[0] === '' || $parts[1] === '') continue;
			$roles = isset($parts[2]) && $parts[2] !== '' ? array_map('trim', explode(',', $parts[2])) : [];
			if($roles && !$user->isSuperuser()) {
				$ok = false;
				foreach($roles as $r) if($user->hasRole($r)) { $ok = true; break; }
				if(!$ok) continue;
			}
			$item = ['label' => $parts[0], 'line' => $n + 1];
			if(preg_match('/^new\s+(\S+)(?:\s+(\S+))?$/i', $parts[1], $m)) {
				$item['type'] = 'new';
				$item['parent'] = $m[1];
				$item['template'] = isset($m[2]) ? $m[2] : '';
			} else if(preg_match('#^(/|https?://)#i', $parts[1])) {
				$item['type'] = 'url';
				$item['url'] = $parts[1];
			} else {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	protected function registerShortcutCommands() {
		foreach($this->getShortcuts() as $sc) {
			if($sc['type'] !== 'new') continue;
			$parentPath = $sc['parent'];
			$tplName = $sc['template'];
			$this->addBuiltin('shortcut.' . $sc['line'], [
				'title' => rtrim($sc['label'], '.… ') . '…',
				'summary' => sprintf($this->_('New page under %s'), $parentPath),
				'group' => 'shortcuts',
				'keywords' => 'new create shortcut',
				'args' => [['name' => 'title', 'type' => 'text', 'label' => $this->_('Title of the new page')]],
				'available' => function() use ($parentPath) {
					$parent = $this->shortcutParent($parentPath);
					return $parent && $parent->addable();
				},
				'handler' => function(array $a) use ($parentPath, $tplName) {
					$parent = $this->shortcutParent($parentPath);
					if(!$parent) throw new WireException(sprintf($this->_('Parent page %s not found.'), $parentPath));
					$template = null;
					if($tplName !== '') {
						$template = $this->wire()->templates->get($this->wire()->sanitizer->name($tplName));
						if(!$template) throw new WireException(sprintf($this->_('Template %s not found.'), $tplName));
					}
					return $this->createChild($parent, $a['title'], $template);
				},
			]);
		}
	}

	protected function shortcutParent($path) {
		$p = ctype_digit((string) $path)
			? $this->wire()->pages->get((int) $path)
			: $this->wire()->pages->get('path=' . $this->wire()->sanitizer->selectorValue($path) . ', include=all');
		if(!$p->id || $p->isTrash()) return null;
		return $p;
	}

	/* ================================================== field editing helpers */

	/**
	 * What kind of quick edit a field supports on this page ('' = not supported)
	 *
	 * @param Page $p
	 * @param Field $f
	 * @return string text|textarea|int|float|checkbox|date|options|page|''
	 */
	public function fieldKind(Page $p, Field $f) {
		$t = $f->type;
		if(!$t) return '';
		$class = $t->className();
		// fieldsets/tabs are layout, not content
		if(strpos($class, 'FieldtypeFieldset') === 0 && $class !== 'FieldtypeFieldsetPage') return '';
		if($t instanceof FieldtypePage) return 'page';
		if($t instanceof FieldtypeOptions) return 'options';
		if($t instanceof FieldtypeCheckbox) return 'checkbox';
		if($class === 'FieldtypeToggle') return 'toggle';
		if($t instanceof FieldtypeDatetime) return 'date';
		if($t instanceof FieldtypeInteger) return 'int';
		if($t instanceof FieldtypeFloat) return 'float';
		$inputClass = (string) $f->get('inputfieldClass');
		$rich = stripos($inputClass, 'CKEditor') !== false || stripos($inputClass, 'TinyMCE') !== false;
		if($t instanceof FieldtypeTextarea) {
			// rich text keeps its markup and toolbar in the native editor
			if($rich || (int) $f->get('contentType') >= 1) return 'editor';
			// the palette input is single-line: multi-line or long text goes to the native editor so nothing is flattened
			if($this->hasMultilineValue($p, $f)) return 'editor';
			return 'textarea';
		}
		if($t instanceof FieldtypeText) return $rich ? 'editor' : 'text';
		// everything else (images, files, repeaters, matrix, combo, table, map, …): native field editor in a modal
		return 'editor';
	}

	/**
	 * Does the page hold a value for this text field that a single-line input could damage?
	 *
	 */
	protected function hasMultilineValue(Page $p, Field $f) {
		$values = [];
		$v = $p->getUnformatted($f->name);
		$languages = $this->wire()->languages;
		if($languages && $f->type instanceof FieldtypeLanguageInterface && $v instanceof LanguagesValueInterface) {
			foreach($languages as $lang) $values[] = (string) $p->getLanguageValue($lang, $f->name);
		} else {
			$values[] = is_object($v) ? (string) $v : (string) $v;
		}
		foreach($values as $text) {
			if(strpbrk($text, "\r\n") !== false || mb_strlen($text) > 1000) return true;
		}
		return false;
	}

	/** Can this field be edited right inside the palette (as opposed to its own editor)? */
	public function isInlineKind($kind) {
		return $kind !== '' && $kind !== 'editor';
	}

	/**
	 * URL of the native page editor showing only one field (modal)
	 *
	 */
	public function fieldEditorUrl(Page $p, Field $f) {
		$url = $p->editUrl();
		return $url . (strpos($url, '?') === false ? '?' : '&') . 'fields=' . rawurlencode($f->name) . '&modal=1';
	}

	/**
	 * Fields of a page that can be quick-edited by the current user
	 *
	 * @param Page $p
	 * @return Field[] keyed by name
	 */
	protected function editableFields(Page $p, $inlineOnly = true) {
		$out = [];
		foreach($p->template->fieldgroup as $f) {
			/** @var Field $f */
			if($f->flags & Field::flagSystem && $f->name !== 'title') continue;
			$ctx = $p->template->fieldgroup->getFieldContext($f);
			$collapsed = (int) ($ctx ? $ctx->collapsed : $f->collapsed);
			if(in_array($collapsed, [Inputfield::collapsedHidden, Inputfield::collapsedNoLocked, Inputfield::collapsedBlankLocked, Inputfield::collapsedLocked], true)) continue;
			$kind = $this->fieldKind($p, $f);
			if($kind === '' || ($inlineOnly && !$this->isInlineKind($kind))) continue;
			if(!$p->editable($f->name)) continue;
			$out[$f->name] = $f;
		}
		return $out;
	}

	protected function editableField(Page $p, $name) {
		$name = $this->wire()->sanitizer->fieldName($name);
		$fields = $this->editableFields($p);
		return isset($fields[$name]) ? $fields[$name] : null;
	}

	/**
	 * Resolve 'name' or 'name@languageId' to an editable field (+ language)
	 *
	 * @return array|null ['field' => Field, 'lang' => Language|null]
	 */
	protected function fieldRef(Page $p, $ref) {
		$ref = (string) $ref;
		$langId = 0;
		if(strpos($ref, '@') !== false) list($ref, $langId) = explode('@', $ref, 2);
		$f = $this->editableField($p, $ref);
		if(!$f || !$this->isInlineKind($this->fieldKind($p, $f))) return null;
		$lang = null;
		if((int) $langId) {
			if(!$this->isLanguageField($f)) return null;
			$lang = $this->wire()->languages->get((int) $langId);
			if(!$lang || !$lang->id) return null;
		}
		return ['field' => $f, 'lang' => $lang];
	}

	protected function isLanguageField(Field $f) {
		$languages = $this->wire()->languages;
		return $languages && $languages->count() > 1 && $f->type instanceof FieldtypeLanguageInterface;
	}

	/**
	 * Parse and set a value on a page, with undo snapshot
	 *
	 * @param Page $p
	 * @param array $ref From fieldRef()
	 * @param string $raw
	 * @param bool $bulk Multi-value fields: add instead of toggle
	 * @throws WireException
	 */
	protected function applyFieldValue(Page $p, array $ref, $raw, $bulk) {
		/** @var Field $f */
		$f = $ref['field'];
		$lang = $ref['lang'];
		$value = $this->parseFieldValue($p, $f, $raw, $bulk);
		$this->pushUndo('fields', $p, sprintf($this->_('%1$s on “%2$s”'), $this->fieldLabel($p, $f), $p->get('title|name')), [
			'fields' => [$f->name => $this->snapshot($p, $f)],
		]);
		$p->of(false);
		if($lang) {
			$p->setLanguageValue($lang, $f->name, $value);
		} else {
			$p->set($f->name, $value);
		}
		$p->save($f->name);
	}

	protected function fieldLabel(Page $p, Field $f) {
		$ctx = $p->template->fieldgroup->getFieldContext($f);
		$label = $ctx ? $ctx->getLabel() : $f->getLabel();
		return $label ? $label : $f->name;
	}

	protected function fieldChoices(?Page $p, $q, $inlineOnly = false) {
		if(!$p) return [];
		$out = [];
		foreach($this->editableFields($p, $inlineOnly) as $name => $f) {
			$label = $this->fieldLabel($p, $f);
			if($q !== '' && stripos($label . ' ' . $name, $q) === false) continue;
			$kind = $this->fieldKind($p, $f);
			if(!$this->isInlineKind($kind)) {
				$preview = $this->displayValue($p, $f);
				if(mb_strlen($preview) > 60) $preview = mb_substr($preview, 0, 60) . '…';
				$out[] = [
					'value' => $name,
					'label' => $label,
					'summary' => $name . ' · ' . ($preview !== '' ? $preview . ' · ' : '') . $this->_('opens its editor'),
					'open' => $this->fieldEditorUrl($p, $f),
				];
				continue;
			}
			$langs = $this->isLanguageField($f) ? $this->wire()->languages : [null];
			foreach($langs as $lang) {
				$preview = $this->displayValue($p, $f, $lang);
				if(mb_strlen($preview) > 70) $preview = mb_substr($preview, 0, 70) . '…';
				$out[] = [
					'value' => $lang ? "$name@$lang->id" : $name,
					'label' => $lang ? $label . ' (' . $lang->get('title|name') . ')' : $label,
					'summary' => $name . ' · ' . ($preview !== '' ? $preview : '—'),
				];
			}
		}
		return $out;
	}

	/**
	 * Choices for the value step, or null when the value is typed freely
	 *
	 */
	protected function valueChoices(Page $p, Field $f, $q) {
		$kind = $this->fieldKind($p, $f);
		$current = $p->getUnformatted($f->name);

		if($kind === 'toggle') {
			$v = $current === null || $current === '' ? null : (int) $current;
			$out = [
				['value' => '1', 'label' => $this->_('Yes / On'), 'selected' => $v === 1],
				['value' => '0', 'label' => $this->_('No / Off'), 'selected' => $v === 0],
			];
			if(!$f->get('required')) $out[] = ['value' => '', 'label' => $this->_('(not set)'), 'selected' => $v === null];
			return $out;
		}

		if($kind === 'checkbox') {
			return [
				['value' => '1', 'label' => $this->_('Yes (checked)'), 'selected' => (bool) $current],
				['value' => '0', 'label' => $this->_('No (unchecked)'), 'selected' => !$current],
			];
		}

		if($kind === 'options') {
			$ids = [];
			if($current instanceof SelectableOptionArray) foreach($current as $o) $ids[] = (int) $o->id;
			$multi = $this->isMultiValue($p, $f);
			$out = [];
			foreach($f->type->getOptions($f) as $o) {
				$title = (string) $o->title;
				if($q !== '' && stripos($title . ' ' . $o->value, $q) === false) continue;
				$on = in_array((int) $o->id, $ids, true);
				$out[] = [
					'value' => (string) $o->id,
					'label' => $title,
					'summary' => $multi ? ($on ? $this->_('selected — choose to remove') : $this->_('choose to add')) : '',
					'selected' => $on,
				];
			}
			if(!$multi && !$f->get('required')) $out[] = ['value' => '0', 'label' => $this->_('(none)'), 'selected' => !$ids];
			return $out;
		}

		if($kind === 'page') {
			return $this->pageRefChoices($p, $f, $q, $current);
		}

		return null; // free text
	}

	/**
	 * Selectable pages for a Page reference field — works for every configuration:
	 * parent, template(s), selector, custom code, or unrestricted (“any page”).
	 *
	 * @param Page $p
	 * @param Field $f
	 * @param string $q
	 * @param mixed $current
	 * @return array
	 */
	protected function pageRefChoices(Page $p, Field $f, $q, $current) {
		$pages = $this->wire()->pages;
		$sanitizer = $this->wire()->sanitizer;
		$multi = $this->isMultiValue($p, $f);
		$limit = 30;

		$selected = $pages->newPageArray();
		if($current instanceof PageArray) $selected->import($current);
		else if($current instanceof Page && $current->id) $selected->add($current);

		$restricted = (int) $f->get('parent_id')
			|| (int) $f->get('template_id')
			|| count(array_filter((array) $f->get('template_ids')))
			|| trim((string) $f->get('findPagesSelector')) !== ''
			|| trim((string) $f->get('findPagesSelect')) !== ''
			|| trim((string) $f->get('findPagesCode')) !== '';

		$filter = ($q !== '' ? 'title|name%=' . $sanitizer->selectorValue($q) . ', ' : '') . "limit=$limit";
		$list = null;
		$inputfield = $f->getInputfield($p);
		if($restricted && $inputfield instanceof InputfieldPage) { // hookable ___getSelectablePages(): method_exists() would be false
			try { $list = $inputfield->getSelectablePages($p, $filter); } catch(\Throwable $e) { $list = null; }
		}
		$checkEach = false;
		if(!$list instanceof PageArray) {
			$list = $pages->newPageArray();
			$checkEach = true;
			if($q !== '') {
				// unrestricted field (any page) or unusual setup: search the site, validate each page
				try {
					$list = $pages->find($this->pageSelector($q) . ', limit=' . ($limit * 2));
				} catch(\Throwable $e) {
					$list = $pages->newPageArray();
				}
			}
		}

		$out = [];
		$seen = [];
		$add = function(Page $sp, $on) use (&$out, &$seen, $multi) {
			if(isset($seen[$sp->id])) return;
			$seen[$sp->id] = true;
			$out[] = [
				'value' => (string) $sp->id,
				'label' => (string) $sp->get('title|name'),
				'summary' => $sp->path . ($multi ? ' · ' . ($on ? $this->_('selected — choose to remove') : $this->_('choose to add')) : ''),
				'selected' => $on,
				'tpl' => (string) $sp->template->name,
			];
		};
		// current values first (only when not searching), so they can be removed
		if($q === '') foreach($selected as $sp) $add($sp, true);
		foreach($list as $sp) {
			if(count($out) >= $limit + $selected->count()) break;
			if(!$sp->id || $sp->template == 'admin' || $sp->isTrash() || $sp->id === $p->id) continue;
			if($checkEach) {
				try {
					if(!$f->type->isValidPage($sp, $f, $p)) continue;
				} catch(\Throwable $e) {
					continue;
				}
			}
			$add($sp, $selected->has($sp));
		}
		if(!$multi && !$f->get('required') && $q === '') {
			$out[] = ['value' => '0', 'label' => $this->_('(none)'), 'summary' => $this->_('clear the field'), 'selected' => !$selected->count()];
		}
		return $out;
	}

	protected function isMultiValue(Page $p, Field $f) {
		if($f->type instanceof FieldtypePage) return (int) $f->get('derefAsPage') === 0;
		$inputfield = $f->getInputfield($p);
		return $inputfield instanceof InputfieldHasArrayValue;
	}

	/**
	 * Current value as editable text (for the free-text step)
	 *
	 */
	protected function editValue(Page $p, Field $f, $lang = null) {
		$v = $lang ? $p->getLanguageValue($lang, $f->name) : $p->getUnformatted($f->name);
		switch($this->fieldKind($p, $f)) {
			case 'date':
				return $v ? date('Y-m-d H:i', (int) $v) : '';
			case 'int':
			case 'float':
				return $v === null || $v === '' ? '' : (string) $v;
			default:
				return is_object($v) ? (string) $v : (string) $v;
		}
	}

	/**
	 * Human readable current value
	 *
	 */
	protected function displayValue(Page $p, Field $f, $lang = null) {
		$v = $lang ? $p->getLanguageValue($lang, $f->name) : $p->getUnformatted($f->name);
		switch($this->fieldKind($p, $f)) {
			case 'checkbox':
				return $v ? $this->_('Yes') : $this->_('No');
			case 'toggle':
				if($v === null || $v === '') return '';
				return (int) $v === 1 ? $this->_('Yes') : ((int) $v === 0 ? $this->_('No') : $this->_('Other'));
			case 'editor':
				return $this->summarizeValue($v);
			case 'date':
				return $v ? date('Y-m-d H:i', (int) $v) : '';
			case 'options':
				return $v instanceof SelectableOptionArray ? implode(', ', $v->explode('title')) : '';
			case 'page':
				if($v instanceof PageArray) return implode(', ', $v->explode('title|name'));
				return $v instanceof Page && $v->id ? (string) $v->get('title|name') : '';
			default:
				return trim(preg_replace('/\s+/', ' ', (string) $v));
		}
	}

	/**
	 * Short text preview of any field value (files, repeaters, rich text, objects)
	 *
	 */
	protected function summarizeValue($v) {
		try {
			if($v instanceof Pageimages) return sprintf($this->_n('%d image', '%d images', $v->count()), $v->count());
			if($v instanceof Pagefiles) return sprintf($this->_n('%d file', '%d files', $v->count()), $v->count());
			if($v instanceof WireArray) return sprintf($this->_n('%d item', '%d items', $v->count()), $v->count());
			if(is_array($v)) return sprintf($this->_n('%d item', '%d items', count($v)), count($v));
			if(is_object($v) && !method_exists($v, '__toString')) return '';
			$text = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], (string) $v))));
			return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		} catch(\Throwable $e) {
			return '';
		}
	}

	/**
	 * Turn the typed/chosen value into something to set on the page
	 *
	 * @throws WireException
	 */
	protected function parseFieldValue(Page $p, Field $f, $raw, $bulk = false) {
		$raw = trim((string) $raw);
		$kind = $this->fieldKind($p, $f);
		$required = (bool) $f->get('required');
		if($raw === '' && $required && !in_array($kind, ['checkbox', 'options', 'page'], true)) {
			throw new WireException($this->_('This field is required.'));
		}

		switch($kind) {
			case 'int':
				if($raw === '') return '';
				if(!preg_match('/^-?\d+$/', $raw)) throw new WireException($this->_('Please enter a whole number.'));
				return (int) $raw;

			case 'float':
				if($raw === '') return '';
				$n = str_replace([' ', ','], ['', '.'], $raw);
				if(!is_numeric($n)) throw new WireException($this->_('Please enter a number.'));
				return (float) $n;

			case 'checkbox':
				return in_array(strtolower($raw), ['1', 'yes', 'on', 'true'], true) ? 1 : 0;

			case 'toggle':
				if($raw === '') return null;
				return in_array(strtolower($raw), ['1', 'yes', 'on', 'true'], true) ? 1 : 0;

			case 'editor':
			case '':
				throw new WireException($this->_('This field is edited in its own editor.'));

			case 'date':
				if($raw === '') return '';
				$ts = ctype_digit($raw) ? (int) $raw : strtotime($raw);
				if(!$ts) throw new WireException($this->_('Unknown date. Try e.g. 2026-11-05 18:00, 5.11.2026 or “next friday”.'));
				return $ts;

			case 'options':
				$id = (int) $raw;
				$valid = [];
				foreach($f->type->getOptions($f) as $o) $valid[] = (int) $o->id;
				if($id && !in_array($id, $valid, true)) throw new WireException($this->_('Unknown option.'));
				if(!$this->isMultiValue($p, $f)) return $id ? $id : '';
				$current = [];
				$v = $p->getUnformatted($f->name);
				if($v instanceof SelectableOptionArray) foreach($v as $o) $current[] = (int) $o->id;
				if(!$id) return $current;
				if($bulk) return in_array($id, $current, true) ? $current : array_merge($current, [$id]);
				$current = in_array($id, $current, true) ? array_values(array_diff($current, [$id])) : array_merge($current, [$id]);
				return $current;

			case 'page':
				$id = (int) $raw;
				$multi = $this->isMultiValue($p, $f);
				if(!$id) {
					if($multi) return $p->getUnformatted($f->name);
					return null;
				}
				$target = $this->wire()->pages->get($id);
				if(!$target->id || !$f->type->isValidPage($target, $f, $p)) {
					throw new WireException($this->_('That page cannot be selected in this field.'));
				}
				if(!$multi) return $target;
				$current = $p->getUnformatted($f->name);
				$current = $current instanceof PageArray ? $current->makeCopy() : $this->wire()->pages->newPageArray();
				if($current->has($target)) {
					if(!$bulk) $current->remove($target);
				} else {
					$current->add($target);
				}
				return $current;

			default:
				return $raw;
		}
	}

	/**
	 * Labels of required fields that are empty — publishing is refused like in the page editor
	 *
	 * @param Page $p
	 * @return array
	 */
	public function missingRequired(Page $p) {
		$missing = [];
		$fieldgroup = $p->template->fieldgroup;
		foreach($fieldgroup as $f) {
			$f = $fieldgroup->getFieldContext($f);
			if(!$f || !$f->get('required')) continue;
			$kind = $this->fieldKind($p, $f);
			if($kind === '') continue;
			$v = $p->getUnformatted($f->name);
			$empty = $v === null || $v === '' || $v === false
				|| ($v instanceof WireArray && !$v->count())
				|| ($v instanceof Page && !$v->id)
				|| ($kind === 'checkbox' && !$v);
			if(!$empty && $kind !== 'checkbox' && is_object($v) && method_exists($v, '__toString') && !$v instanceof WireArray && !$v instanceof Page) {
				$empty = trim((string) $v) === '';
			}
			if($empty) $missing[] = $this->fieldLabel($p, $f);
		}
		return $missing;
	}

	/* ============================================================ page info */

	protected function referencesNote(?Page $p) {
		if(!$p) return '';
		try {
			$refs = $p->references('include=unpublished');
			$links = $p->links('include=unpublished');
		} catch(\Throwable $e) {
			return '';
		}
		$all = new PageArray();
		$all->import($refs);
		$all->import($links);
		$n = $all->count();
		if(!$n) return '';
		$names = implode(', ', array_slice($all->explode('title|name'), 0, 3));
		return sprintf($this->_n('⚠ %1$d page points to it (%2$s).', '⚠ %1$d pages point to it (%2$s…).', $n), $n, $names);
	}

	protected function pageInfo(Page $p) {
		$users = $this->wire()->users;
		$rows = [];
		$rows[] = ['ID · ' . $this->_('template'), $p->id . ' · ' . $p->template->name];

		$flags = [];
		if($p->isUnpublished()) $flags[] = $this->_('unpublished');
		if($p->isHidden()) $flags[] = $this->_('hidden');
		if($p->isLocked()) $flags[] = $this->_('locked');
		$rows[] = [$this->_('Status'), $flags ? implode(', ', $flags) : $this->_('published')];

		$languages = $this->wire()->languages;
		if($languages && $this->wire()->modules->isInstalled('LanguageSupportPageNames')) {
			foreach($languages as $lang) {
				$rows[] = ['URL · ' . $lang->get('title|name'), $p->localHttpUrl($lang)];
			}
		} else {
			$rows[] = ['URL', $p->httpUrl];
		}

		$who = function($id) use ($users) {
			$u = $users->get((int) $id);
			return $u && $u->id ? $u->name : '?';
		};
		$rows[] = [$this->_('Created'), date('Y-m-d H:i', $p->created) . ' · ' . $who($p->created_users_id)];
		$rows[] = [$this->_('Modified'), date('Y-m-d H:i', $p->modified) . ' · ' . $who($p->modified_users_id)];
		if($p->published) $rows[] = [$this->_('Published'), date('Y-m-d H:i', $p->published)];
		$rows[] = [$this->_('Children'), (string) $p->numChildren('include=all')];

		try {
			$refs = $p->references('include=unpublished');
			$rows[] = [
				$this->_('Referenced by (page fields)'),
				$refs->count() ? $refs->count() . ': ' . implode(', ', array_slice($refs->explode('title|name'), 0, 8)) : '—',
			];
		} catch(\Throwable $e) { /* older core */ }
		try {
			$links = $p->links('include=unpublished');
			$rows[] = [
				$this->_('Linked from (text)'),
				$links->count() ? $links->count() . ': ' . implode(', ', array_slice($links->explode('title|name'), 0, 8)) : '—',
			];
		} catch(\Throwable $e) { /* link tracking disabled */ }

		return [
			'message' => (string) $p->get('title|name'),
			'rows' => $rows,
			'open' => $p->editable() ? $p->editUrl() : '',
		];
	}

	/* ================================================================= undo */

	protected $undoPushed = false;

	public function getUndoStack() {
		$stack = $this->wire()->session->getFor($this, 'undo');
		return is_array($stack) ? $stack : [];
	}

	protected function setUndoStack(array $stack) {
		$this->wire()->session->setFor($this, 'undo', array_slice($stack, 0, 10));
	}

	/**
	 * Remember how to reverse a change (kept per user session, last 10)
	 *
	 * @param string $type fields|status|parent|trash|created
	 * @param Page $p
	 * @param string $label
	 * @param array $data
	 */
	public function pushUndo($type, Page $p, $label, array $data) {
		$rec = ['type' => $type, 'page' => (int) $p->id, 'label' => (string) $label, 'time' => time(), 'data' => $data];
		if(is_array($this->undoBatch)) {
			$this->undoBatch[] = $rec;
			return;
		}
		$stack = $this->getUndoStack();
		array_unshift($stack, $rec);
		$this->setUndoStack($stack);
		$this->undoPushed = true;
	}

	/** @var array|null Collected records while a bulk command runs */
	protected $undoBatch = null;

	protected function beginUndoBatch() {
		$this->undoBatch = [];
	}

	protected function endUndoBatch($label) {
		$items = (array) $this->undoBatch;
		$this->undoBatch = null;
		if(!$items) return;
		$stack = $this->getUndoStack();
		array_unshift($stack, ['type' => 'batch', 'page' => 0, 'label' => (string) $label, 'time' => time(), 'data' => ['items' => $items]]);
		$this->setUndoStack($stack);
		$this->undoPushed = true;
	}

	protected function pushStatusUndo(Page $p, $what) {
		$this->pushUndo('status', $p, sprintf($this->_('%1$s of “%2$s”'), $what, $p->get('title|name')), ['status' => (int) $p->status]);
	}

	protected function snapshot(Page $p, Field $f) {
		return $f->type->sleepValue($p, $f, $p->getUnformatted($f->name));
	}

	protected function undoLast() {
		$stack = $this->getUndoStack();
		if(!$stack) throw new WireException($this->_('Nothing to undo.'));
		$rec = $stack[0];

		if($rec['type'] === 'batch') {
			$ok = 0;
			$failed = [];
			foreach(array_reverse((array) ($rec['data']['items'] ?? [])) as $sub) {
				try {
					$this->applyUndo($sub);
					$ok++;
				} catch(\Throwable $e) {
					$failed[] = [$sub['label'], $e instanceof WireException ? $e->getMessage() : $this->_('error')];
				}
				$this->wire()->pages->uncacheAll();
			}
			array_shift($stack);
			$this->setUndoStack($stack);
			return [
				'message' => sprintf($this->_('Undone: %1$s — %2$d restored'), $rec['label'], $ok),
				'rows' => array_slice($failed, 0, 10),
				'type' => $failed && !$ok ? 'error' : 'success',
				'reload' => !$failed,
			];
		}

		if(!$this->wire()->pages->get((int) $rec['page'])->id) {
			array_shift($stack);
			$this->setUndoStack($stack);
			throw new WireException($this->_('The page no longer exists — nothing to undo.'));
		}
		$result = $this->applyUndo($rec);
		array_shift($stack);
		$this->setUndoStack($stack);
		return $result;
	}

	/**
	 * Reverse one undo record
	 *
	 * @param array $rec
	 * @return array Command result
	 * @throws WireException
	 */
	protected function applyUndo(array $rec) {
		$pages = $this->wire()->pages;
		$p = $pages->get((int) $rec['page']);
		if(!$p->id) throw new WireException($this->_('The page no longer exists.'));
		$data = (array) $rec['data'];
		$result = ['message' => sprintf($this->_('Undone: %s'), $rec['label']), 'reload' => true];

		switch($rec['type']) {
			case 'fields':
				if(!$p->editable()) throw new WireException($this->_('You can no longer edit this page.'));
				$p->of(false);
				foreach((array) ($data['fields'] ?? []) as $name => $sleep) {
					$f = $this->wire()->fields->get($name);
					if(!$f || !$p->template->fieldgroup->hasField($f) || !$p->editable($name)) continue;
					$p->set($name, $f->type->wakeupValue($p, $f, $sleep));
					$p->save($name);
				}
				break;

			case 'status':
				if(!$p->editable()) throw new WireException($this->_('You can no longer edit this page.'));
				$old = (int) ($data['status'] ?? 0);
				$p->of(false);
				foreach([Page::statusUnpublished, Page::statusHidden] as $bit) {
					if($bit === Page::statusUnpublished && !$p->publishable()) continue;
					if($old & $bit) $p->addStatus($bit); else $p->removeStatus($bit);
				}
				$p->save();
				break;

			case 'parent':
				$old = $pages->get((int) ($data['parent'] ?? 0));
				if(!$old->id) throw new WireException($this->_('The previous parent no longer exists.'));
				if(!$p->moveable($old)) throw new WireException($this->_('You are not allowed to move the page back.'));
				$p->of(false);
				$p->parent = $old;
				if(isset($data['sort'])) $p->sort = (int) $data['sort'];
				if(!empty($data['name']) && $data['name'] !== $p->name) {
					// the move renamed it: take the original name back if it is free again
					$p->name = $pages->names()->uniquePageName((string) $data['name'], $p);
				} else if($pages->count("parent_id=$old->id, name=" . $this->wire()->sanitizer->selectorValue($p->name) . ", id!=$p->id, include=all")) {
					$p->name = $pages->names()->uniquePageName($p->name, $p);
				}
				$p->save();
				if($this->clientContext === 'front') $result = ['message' => $result['message'], 'redirect' => $p->url];
				break;

			case 'trash':
				if(!$p->isTrash()) {
					$result = ['message' => $this->_('The page is not in the trash anymore.')];
					break;
				}
				if($p->restorable()) {
					$pages->restore($p);
				} else {
					$this->restoreFromUndoData($p, $data);
				}
				$result['reload'] = false;
				$result['redirect'] = $this->clientContext === 'front' ? $p->url : $p->editUrl();
				break;

			case 'created':
				if(!$p->trashable()) throw new WireException($this->_('You are not allowed to trash the created page.'));
				$parent = $p->parent;
				$back = $pages->get((int) ($data['back'] ?? 0));
				$pages->trash($p);
				$result['reload'] = false;
				if($back->id && $back->editable()) $result['redirect'] = $this->clientContext === 'front' ? $back->url : $back->editUrl();
				else $result['redirect'] = $this->clientContext === 'front' ? $parent->url : $this->wire()->config->urls->admin . 'page/?open=' . $parent->id;
				break;

			default:
				throw new WireException('Unknown undo record');
		}
		return $result;
	}

	/**
	 * Move a page under a new parent (with undo); makes the name unique when the parent
	 * already has a page with the same name, instead of failing
	 *
	 * @return bool True when the page had to be renamed
	 * @throws WireException
	 */
	public function movePage(Page $p, Page $parent) {
		$pages = $this->wire()->pages;
		$fresh = $this->trashUndoData($p); // parent/sort/name as stored in the database
		$this->pushUndo('parent', $p, sprintf($this->_('Move of “%s”'), $p->get('title|name')), $fresh);
		$p->of(false);
		$p->parent = $parent;
		$renamed = false;
		$taken = $pages->count("parent_id=$parent->id, name=" . $this->wire()->sanitizer->selectorValue($p->name) . ", id!=$p->id, include=all");
		if($taken) {
			$p->name = $pages->names()->uniquePageName($p->name, $p);
			$renamed = true;
		}
		$p->save();
		return $renamed;
	}

	/**
	 * Where a page lived before it was trashed — read fresh from the database
	 *
	 */
	protected function trashUndoData(Page $p) {
		$row = ['parent' => (int) $p->parent_id, 'sort' => (int) $p->sort, 'name' => (string) $p->name];
		try {
			$query = $this->wire()->database->prepare('SELECT parent_id, sort, name FROM pages WHERE id=:id');
			$query->bindValue(':id', (int) $p->id, \PDO::PARAM_INT);
			$query->execute();
			$db = $query->fetch(\PDO::FETCH_ASSOC);
			$query->closeCursor();
			if($db) $row = ['parent' => (int) $db['parent_id'], 'sort' => max(0, (int) $db['sort']), 'name' => (string) $db['name']];
		} catch(\Throwable $e) {
			// fall back to the in-memory values
		}
		return $row;
	}

	/**
	 * Restore a trashed page using the location saved before trashing
	 * (used when ProcessWire's own restore information is not usable)
	 *
	 * @throws WireException
	 */
	protected function restoreFromUndoData(Page $p, array $data) {
		$pages = $this->wire()->pages;
		$parent = $pages->get((int) ($data['parent'] ?? 0));
		if(!$parent->id || $parent->isTrash()) throw new WireException($this->_('The original parent page no longer exists.'));
		if(!$p->editable() || !$parent->addable($p)) throw new WireException($this->_('You are not allowed to restore this page.'));
		$p->of(false);
		$p->parent = $parent;
		$p->removeStatus(Page::statusTrash);
		$name = (string) ($data['name'] ?? '');
		if($name === '') $name = preg_replace('/^\d+\.\d+\.-?\d+_/', '', $p->name);
		$p->name = $pages->names()->uniquePageName($name, $p);
		if(isset($data['sort'])) $p->sort = (int) $data['sort'];
		$pages->save($p);
	}

	/**
	 * Create a child page with the given title, or redirect to the Add Page screen
	 * when the template cannot be decided automatically.
	 *
	 * @param Page $parent
	 * @param string $title
	 * @return array Command result
	 * @throws WireException
	 */
	public function createChild(Page $parent, $title, ?Template $forceTemplate = null) {
		if($parent->template->noChildren || !$parent->addable()) {
			throw new WireException($this->_('You cannot add pages under that page.'));
		}
		$templates = $this->wire()->templates;
		$pages = $this->wire()->pages;
		$addUrl = $this->wire()->config->urls->admin . 'page/add/?parent_id=' . $parent->id;

		$template = null;
		$childIds = (array) $parent->template->childTemplates;
		if($forceTemplate) {
			$template = $forceTemplate;
			if(count($childIds) && !in_array($template->id, $childIds)) {
				throw new WireException(sprintf($this->_('Template “%1$s” is not allowed under “%2$s”.'), $template->name, $parent->get('title|name')));
			}
		} else if(count($childIds) === 1) {
			$template = $templates->get((int) reset($childIds));
		} else if(!count($childIds) && $parent->numChildren('include=all')) {
			// infer from existing children when all share one template
			$used = [];
			foreach($parent->children('include=all, limit=20') as $c) $used[$c->template->id] = $c->template;
			if(count($used) === 1) $template = reset($used);
		}

		$ok = $template instanceof Template
			&& !$template->noParents
			&& (!count($template->parentTemplates) || in_array($parent->template->id, $template->parentTemplates))
			&& $template->hasField('title');

		if(!$ok) {
			return ['message' => $this->_('Choose a template on the Add Page screen.'), 'redirect' => $addUrl];
		}

		$p = $pages->newPage(['template' => $template, 'parent' => $parent]);
		$p->of(false);
		$p->set('title', $title);
		$name = $this->wire()->sanitizer->pageNameTranslate($title);
		if($name === '') $name = 'page';
		$p->name = $pages->names()->uniquePageName($name, $p);
		$p->addStatus(Page::statusUnpublished);
		$pages->save($p);
		$this->pushUndo('created', $p, sprintf($this->_('New page “%s”'), $title), []);

		return ['message' => sprintf($this->_('Created “%s” (unpublished).'), $title), 'redirect' => $p->editUrl()];
	}

	/* ========================================================= static items */

	protected function getStaticItems(?Page $page) {
		$items = [];
		if($this->source('commands')) {
			foreach($this->getCommands() as $id => $def) {
				if($this->commandAvailable($def, $page)) {
					$items[] = $this->commandItem($id, $def, $page);
				} else if(!$page && $def['context'] === 'page' && (!$def['permission'] || $this->can($def['permission']))) {
					// shown in help only: what becomes possible once a page is open
					$items[] = ['t' => (string) $def['title'], 's' => (string) $def['summary'], 'g' => 'catalog'];
				}
			}
		}
		if($page) {
			if($page->editable()) {
				$items[] = ['t' => $this->_('Edit this page'), 's' => $page->get('title|name'), 'u' => $page->editUrl(), 'g' => 'page', 'k' => 'edit modify', 'id' => (int) $page->id, 'tpl' => (string) $page->template->name];
			}
			if($page->viewable(false)) {
				$items[] = ['t' => $this->_('View this page'), 's' => $page->path, 'u' => $page->url, 'g' => 'page', 'k' => 'view frontend open'];
			}
		}
		foreach($this->getShortcuts() as $sc) {
			if($sc['type'] === 'url') $items[] = ['t' => $sc['label'], 's' => $sc['url'], 'u' => $sc['url'], 'g' => 'shortcuts', 'k' => 'shortcut'];
		}
		if($this->source('actions')) $items = array_merge($items, $this->getActions());
		$items = array_merge($items, $this->getRecentlyEdited());
		if($this->source('nav')) $items = array_merge($items, $this->getNav());
		$custom = $this->getCustomLinks('', '');
		if(is_array($custom)) $items = array_merge($items, $custom);
		return $items;
	}

	protected function getNav() {
		$pages = $this->wire()->pages;
		$items = [];
		$admin = $pages->get((int) $this->wire()->config->adminRootPageID);
		if(!$admin->id) return $items;

		foreach($admin->children('include=hidden, check_access=0') as $top) {
			if(!$this->adminPageOk($top)) continue;
			$topTitle = (string) $top->get('title|name');
			$items[] = ['t' => $topTitle, 's' => $this->_('Admin'), 'u' => $top->url, 'g' => 'nav', 'k' => $top->name];
			foreach($top->children('check_access=0') as $child) {
				if(!$this->adminPageOk($child)) continue;
				$childTitle = (string) $child->get('title|name');
				$items[] = ['t' => $childTitle, 's' => $topTitle, 'u' => $child->url, 'g' => 'nav', 'k' => $child->name];
				$items = array_merge($items, $this->processNavItems($child, $topTitle . ' › ' . $childTitle));
			}
		}
		return $items;
	}

	/**
	 * Sub-screens a Process module declares in its module info 'nav' (e.g. “Add New”)
	 *
	 */
	protected function processNavItems(Page $adminPage, $context) {
		$items = [];
		$info = $this->wire()->modules->getModuleInfoVerbose($this->processName($adminPage));
		if(empty($info['nav']) || !is_array($info['nav'])) return $items;
		foreach($info['nav'] as $nav) {
			if(empty($nav['url']) || empty($nav['label'])) continue;
			if(!empty($nav['permission']) && !$this->can($nav['permission'])) continue;
			$url = (string) $nav['url'];
			if(strpos($url, '/') === 0 || preg_match('#^https?://#', $url)) continue; // only relative sub-screens
			$items[] = ['t' => (string) $nav['label'], 's' => $context, 'u' => $adminPage->url . ltrim($url, './'), 'g' => 'nav', 'k' => $adminPage->name];
		}
		return $items;
	}

	protected function adminPageOk(Page $p) {
		if($p->isUnpublished() || in_array($p->name, ['login', 'logout'], true)) return false;
		$process = $this->processName($p);
		if($process === '') return false;
		$modules = $this->wire()->modules;
		if(!$modules->isInstalled($process)) return false;
		$info = $modules->getModuleInfo($process);
		if(!empty($info['permission']) && !$this->can($info['permission'])) return false;
		return $p->viewable(false);
	}

	protected function getActions() {
		$config = $this->wire()->config;
		$a = $config->urls->admin;
		$acts = [];
		$add = function($t, $u, $k = '') use (&$acts) {
			$acts[] = ['t' => $t, 's' => '', 'u' => $u, 'g' => 'actions', 'k' => $k];
		};
		$add($this->_('Page tree'), $a . 'page/', 'pages tree list');
		if($this->can('page-edit')) $add($this->_('Add new page (screen)'), $a . 'page/add/', 'create new');
		if($this->can('template-admin')) $add($this->_('Add new template'), $a . 'setup/template/add/', 'create new');
		if($this->can('field-admin')) $add($this->_('Add new field'), $a . 'setup/field/add/', 'create new');
		if($this->can('user-admin')) $add($this->_('Add new user'), $a . 'access/users/add/', 'create new account');
		if($this->can('logs-view')) $add($this->_('Logs'), $a . 'setup/logs/', 'errors exceptions');
		if($this->can('profile-edit')) $add($this->_('My profile'), $a . 'profile/', 'password account');
		$add($this->_('View site'), $config->urls->root, 'home frontend');
		$add($this->_('Log out'), $a . 'login/logout/', 'sign out exit');
		return $acts;
	}

	protected function getRecentlyEdited() {
		$config = $this->wire()->config;
		$items = [];
		$selector =
			'modified_users_id=' . (int) $this->wire()->user->id .
			', include=unpublished, status<' . Page::statusTrash .
			', has_parent!=' . (int) $config->adminRootPageID .
			', template!=admin, sort=-modified, limit=6';
		try {
			foreach($this->wire()->pages->find($selector) as $p) {
				$item = $this->pageItem($p, 'edited');
				if($item) $items[] = $item;
			}
		} catch(\Throwable $e) {
			// ignore — not critical
		}
		return $items;
	}

	/* ================================================================ search */

	protected function search($q, $scope = '') {
		$items = [];
		$want = function($name) use ($scope) {
			return ($scope === '' || $scope === $name) && $this->source($name);
		};
		if($want('pages')) $items = array_merge($items, $this->searchPages($q));
		if($want('templates') && $this->can('template-admin')) $items = array_merge($items, $this->searchTemplates($q));
		if($want('fields') && $this->can('field-admin')) $items = array_merge($items, $this->searchFields($q));
		if($want('modules') && $this->can('module-admin')) $items = array_merge($items, $this->searchModules($q));
		if($want('users') && $this->can('user-admin')) $items = array_merge($items, $this->searchUsers($q));
		$custom = $this->getCustomLinks($q, $scope);
		if(is_array($custom)) $items = array_merge($items, $custom);
		return $items;
	}

	protected function searchPages($q) {
		$pages = $this->wire()->pages;
		$config = $this->wire()->config;
		$max = max(3, (int) $this->maxResults);
		$found = new PageArray();

		if(ctype_digit($q)) {
			$p = $pages->get((int) $q);
			if($p->id) $found->add($p);
		}
		try {
			$found->import($pages->find($this->pageSelector($q) . ', sort=-modified, limit=' . ($max * 3)));
			$tplName = $this->wire()->sanitizer->name($q);
			if(!$this->looksLikeSelector($q) && $tplName !== '' && $this->wire()->templates->get($tplName)) {
				$found->import($pages->find("template=$tplName, " . $this->baseSelector() . ', sort=-modified, limit=' . $max));
			}
		} catch(\Throwable $e) {
			// invalid selector input
		}

		$items = [];
		foreach($found as $p) {
			if(count($items) >= $max) break;
			$item = $this->pageItem($p, 'pages');
			if($item) $items[] = $item;
		}
		return $items;
	}

	protected function baseSelector() {
		return 'include=unpublished, status<' . Page::statusTrash . ', has_parent!=' . (int) $this->wire()->config->adminRootPageID . ', template!=admin';
	}

	protected function looksLikeSelector($q) {
		return (bool) preg_match('/[=<>]/', $q);
	}

	/**
	 * Build a safe find() selector from what the user typed:
	 * a ProcessWire selector (template=event, date<today) or plain words.
	 * Access-control and paging keywords from the user are dropped.
	 *
	 * @param string $q
	 * @return string
	 * @throws WireException on invalid selectors
	 */
	protected function pageSelector($q) {
		if(!$this->looksLikeSelector($q)) {
			return 'title|name%=' . $this->wire()->sanitizer->selectorValue($q) . ', ' . $this->baseSelector();
		}
		/** @var Selectors $selectors */
		$selectors = $this->wire(new Selectors());
		$selectors->init($q);
		$drop = [];
		foreach($selectors as $sel) {
			$fieldName = is_array($sel->field) ? implode('|', $sel->field) : (string) $sel->field;
			if(preg_match('/(^|\|)(include|check_access|access|limit|start|status|has_parent)(\||$)/i', $fieldName)) $drop[] = $sel;
		}
		foreach($drop as $sel) $selectors->remove($sel);
		$this->assertSelectorFieldsViewable($selectors);
		$str = trim((string) $selectors);
		if($str === '') throw new WireException($this->_('Empty selector.'));
		return $str . ', ' . $this->baseSelector();
	}

	/**
	 * Selectors typed by non-superusers may only use fields the user is allowed to view
	 * (otherwise the result list could reveal values of restricted fields)
	 *
	 * @param Selectors $selectors
	 * @throws WireException
	 */
	protected function assertSelectorFieldsViewable(Selectors $selectors) {
		$user = $this->wire()->user;
		if($user->isSuperuser()) return;
		$fields = $this->wire()->fields;
		foreach($selectors as $sel) {
			$names = is_array($sel->field) ? $sel->field : explode('|', (string) $sel->field);
			foreach($names as $name) {
				$base = explode('.', trim((string) $name))[0];
				$f = $base !== '' ? $fields->get($base) : null;
				if(!$f instanceof Field) continue; // native properties: id, parent, template, created…
				if(($f->flags & Field::flagSystem) && $f->name !== 'title') {
					if($f->name === 'pass' || $f->name === 'roles' || $f->name === 'permissions') {
						throw new WireException(sprintf($this->_('You cannot search by “%s”.'), $base));
					}
				}
				if($f->get('useRoles') && !$f->viewable(null, $user)) {
					throw new WireException(sprintf($this->_('You cannot search by “%s”.'), $base));
				}
			}
		}
	}

	/**
	 * Pages for the bulk picker (editable only)
	 *
	 */
	protected function findForBulk($q) {
		if($q === '') return ['ok' => true, 'total' => 0, 'items' => []];
		$pages = $this->wire()->pages;
		try {
			$selector = $this->pageSelector($q);
			$total = $pages->count($selector);
			$list = $pages->find($selector . ', sort=-modified, limit=' . self::BULK_MAX);
		} catch(\Throwable $e) {
			return ['ok' => false, 'message' => sprintf($this->_('Invalid selector: %s'), $e->getMessage())];
		}
		$items = [];
		foreach($list as $p) {
			if(!$p->editable()) continue;
			$item = $this->pageItem($p, 'pages');
			if($item) $items[] = ['id' => $item['id'], 't' => $item['t'], 's' => $item['s'], 'tpl' => $item['tpl']];
		}
		return ['ok' => true, 'total' => $total, 'items' => $items];
	}

	protected function pageItem(Page $p, $group) {
		if(!$p->id || $p->template == 'admin' || $p->isTrash()) return null;
		$editable = $p->editable();
		$viewable = $p->viewable(false);
		if(!$editable && !$viewable) return null;
		$meta = [$p->path];
		if($p->isUnpublished()) $meta[] = $this->_('unpublished');
		if($p->isHidden()) $meta[] = $this->_('hidden');
		return [
			't' => (string) $p->get('title|name'),
			's' => implode(' · ', $meta),
			'u' => $editable ? $p->editUrl() : $p->url,
			'v' => $viewable ? $p->url : '',
			'g' => $group,
			'id' => (int) $p->id,
			'tpl' => (string) $p->template->name,
			'k' => $p->name . ' ' . $p->id . ' ' . $p->template->name,
		];
	}

	protected function searchTemplates($q) {
		$a = $this->wire()->config->urls->admin;
		$su = $this->wire()->user->isSuperuser();
		$max = max(3, (int) $this->maxResults);
		$items = [];
		foreach($this->wire()->templates as $t) {
			if(($t->flags & Template::flagSystem) && !$su) continue;
			if(stripos($t->name . ' ' . $t->label, $q) === false) continue;
			$n = $t->getNumPages();
			$items[] = [
				't' => $t->label ? $t->label : $t->name,
				's' => $t->name . ' · ' . sprintf($this->_n('%d page', '%d pages', $n), $n),
				'u' => $a . 'setup/template/edit?id=' . $t->id,
				'g' => 'templates',
				'id' => (int) $t->id,
				'k' => $t->name,
			];
			if(count($items) >= $max) break;
		}
		return $items;
	}

	protected function searchFields($q) {
		$a = $this->wire()->config->urls->admin;
		$su = $this->wire()->user->isSuperuser();
		$max = max(3, (int) $this->maxResults);
		$items = [];
		foreach($this->wire()->fields as $f) {
			if(($f->flags & Field::flagSystem) && !$su) continue;
			if(stripos($f->name . ' ' . $f->label, $q) === false) continue;
			$type = $f->type ? str_replace('Fieldtype', '', $f->type->className()) : '?';
			$items[] = [
				't' => $f->label ? $f->label : $f->name,
				's' => $f->name . ' · ' . $type,
				'u' => $a . 'setup/field/edit?id=' . $f->id,
				'g' => 'fields',
				'id' => (int) $f->id,
				'k' => $f->name,
			];
			if(count($items) >= $max) break;
		}
		return $items;
	}

	protected function searchModules($q) {
		$modules = $this->wire()->modules;
		$a = $this->wire()->config->urls->admin;
		$max = max(3, (int) $this->maxResults);
		$items = [];
		foreach($modules as $m) {
			$name = $modules->getModuleClass($m);
			if(!$name) continue;
			$title = (string) $modules->getModuleInfoProperty($name, 'title');
			if(stripos($name . ' ' . $title, $q) === false) continue;
			$items[] = [
				't' => $title !== '' ? $title : $name,
				's' => $name . ' · ' . $modules->getModuleInfoProperty($name, 'versionStr'),
				'u' => $a . 'module/edit?name=' . rawurlencode($name),
				'g' => 'modules',
				'k' => $name,
			];
			if(count($items) >= $max) break;
		}
		return $items;
	}

	protected function searchUsers($q) {
		$a = $this->wire()->config->urls->admin;
		$v = $this->wire()->sanitizer->selectorValue($q);
		$max = max(3, (int) $this->maxResults);
		$items = [];
		try {
			foreach($this->wire()->users->find("name|email%=$v, limit=$max") as $u) {
				$items[] = [
					't' => $u->name,
					's' => trim($u->email . ' · ' . implode(', ', $u->roles->explode('name')), ' ·'),
					'u' => $a . 'access/users/edit/?id=' . $u->id,
					'g' => 'users',
					'id' => (int) $u->id,
					'k' => (string) $u->email,
				];
			}
		} catch(\Throwable $e) {
			// ignore
		}
		return $items;
	}

	/**
	 * Hook after to add plain navigation items (links).
	 * For executable commands use registerCommands() + addCommand().
	 *
	 * @param string $q Search query ('' when loading the initial list)
	 * @param string $scope Active scope ('' = all)
	 * @return array Items [t, s, u, v?, g?, k?]
	 */
	public function ___getCustomLinks($q, $scope) {
		return [];
	}

	/* ========================================================= module config */

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$modules = $this->wire()->modules;

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Basics');
		$fs->icon = 'keyboard-o';
		$inputfields->add($fs);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'shortcutMod');
		$f->label = $this->_('Shortcut modifier');
		$f->addOptions($this->shortcutMods());
		$f->attr('value', $this->shortcutMod());
		$f->required = true;
		$f->notes = $this->_('Ctrl/⌘+K and Ctrl/⌘+Shift+K open the search of the Konkat admin theme; Ctrl/⌘+P replaces printing. Whatever you choose, the palette takes the shortcut so nothing else opens with it.');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'shortcut');
		$f->label = $this->_('Shortcut key');
		$f->description = sprintf($this->_('One letter or digit. Current shortcut: %s'), $this->shortcutLabel());
		$f->attr('maxlength', 1);
		$f->attr('size', 3);
		$f->attr('value', $this->shortcutKey());
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'slashShortcut');
		$f->label = $this->_('Also open with “/”');
		$f->description = $this->_('Only when no input field is focused.');
		$f->attr('checked', $this->slashShortcut ? 'checked' : '');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'frontend');
		$f->label = $this->_('Enable on the front-end');
		$f->description = $this->_('For logged-in editors: run page commands on the page you are viewing.');
		$f->attr('checked', $this->frontend ? 'checked' : '');
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'shortcuts');
		$f->label = $this->_('Shortcuts');
		$f->icon = 'bolt';
		$f->description = $this->_('Your own commands, one per line: Label | target | roles (optional, comma separated). Target is a URL, or “new /parent/path/ template” to create a page there after asking for its title.');
		$f->notes = "New news post | new /news/ news-item | editor\nOrders | /processwire/shop/orders/\nGoogle Analytics | https://analytics.google.com/";
		$f->attr('rows', 5);
		$f->attr('value', (string) $this->shortcuts);
		$f->collapsed = Inputfield::collapsedBlank;
		$inputfields->add($f);

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Advanced');
		$fs->icon = 'sliders';
		$fs->collapsed = Inputfield::collapsedYes;
		$inputfields->add($fs);

		$f = $modules->get('InputfieldCheckboxes');
		$f->attr('name', 'sources');
		$f->label = $this->_('Sources');
		$f->description = $this->_('Permissions are always checked per user and per page.');
		$f->optionColumns = 4;
		$f->addOptions([
			'commands' => $this->_('Commands'),
			'nav' => $this->_('Admin screens'),
			'actions' => $this->_('Shortcuts'),
			'pages' => $this->_('Pages'),
			'templates' => $this->_('Templates'),
			'fields' => $this->_('Fields'),
			'modules' => $this->_('Modules'),
			'users' => $this->_('Users'),
		]);
		$f->attr('value', (array) $this->sources);
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'maxResults');
		$f->label = $this->_('Results per group');
		$f->attr('min', 3);
		$f->attr('max', 50);
		$f->attr('value', (int) $this->maxResults);
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'logCommands');
		$f->label = $this->_('Log commands that change data');
		$f->description = sprintf($this->_('Written to the “%s” log (Setup > Logs).'), self::LOG);
		$f->attr('checked', $this->logCommands ? 'checked' : '');
		$f->columnWidth = 50;
		$fs->add($f);

		// registered commands overview
		$sanitizer = $this->wire()->sanitizer;
		$rows = '';
		foreach($this->getCommands() as $id => $def) {
			$rows .= '<tr><td><code>' . $sanitizer->entities($id) . '</code></td>' .
				'<td>' . $sanitizer->entities($def['title']) . '</td>' .
				'<td>' . ($def['context'] === 'page' ? $this->_('page') : $this->_('global')) . '</td>' .
				'<td>' . $sanitizer->entities($def['permission'] ?: '—') . '</td>' .
				'<td>' . ($def['mutates'] ? $this->_('changes data') : $this->_('read-only')) . '</td></tr>';
		}
		/** @var InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->label = sprintf($this->_('Registered commands (%d)'), count($this->commands));
		$f->icon = 'list';
		$f->collapsed = Inputfield::collapsedYes;
		$f->description = $this->_('Other modules can add commands by hooking NativeCommand::registerCommands — see README.');
		$f->value = '<table class="uk-table uk-table-small uk-table-divider AdminDataTable"><thead><tr>' .
			'<th>ID</th><th>' . $this->_('Title') . '</th><th>' . $this->_('Context') . '</th><th>' . $this->_('Permission') . '</th><th>' . $this->_('Type') . '</th>' .
			'</tr></thead><tbody>' . $rows . '</tbody></table>';
		$inputfields->add($f);
	}
}
