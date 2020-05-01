<?php
/**
 * Manage WordPress plugin dependencies using WP-CLI and Composer.
 *
 * Register commands to use with WP-CLI.
 *
 * @author De'Yonté Wilkinson <dev@rxnlabs.com>
 * @version 1.0.2
 */
namespace rxnlabs;
use \rxnlabs\Dependencies as Dependencies;
class WPCLI
{

	protected $composer;

	/**
	 * Prevent hooks from running.
	 *
	 * Cancel hooks. This plugin hooks into the wp plugin/theme install and uninstall commands. So when a plugin is installed through wp plugin install, it automatically gets added to the composer.json file
	 *
	 * @since 1.0.1
	 * @version 1.0.0
	 *
	 * @var bool
	 */
	private $run_hooks = true;

	/**
	 * WPCLI constructor.
	 * @param \rxnlabs\Dependencies $composer
	 */
	public function __construct(Dependencies $composer)
	{
		// Run the Composer command.
		$this->composer = $composer;
	}

	/**
	 * Register commands for use with WP CLI
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public function registerCommands()
	{
		$callbacks = [
			'before_invoke' => function() {
				$this->setInstallerPath();
			}
		];
		\WP_CLI::add_command('composer plugins', array($this, 'plugins'), $callbacks);
		\WP_CLI::add_command('composer themes', array($this, 'themes'), $callbacks);
		\WP_CLI::add_command('composer add', array($this, 'addAllDependencies'), $callbacks);
		\WP_CLI::add_command('composer plugin', array($this, 'plugin'), $callbacks);
		\WP_CLI::add_command('composer theme', array($this, 'theme'), $callbacks);
		\WP_CLI::add_command('composer install', array($this, 'installDependencies'));
	}

	/**
	 * Add hooks to be fired after other commands
	 *
	 * On plugin or theme install/uninstall/removal using WP-CLI, that plugin or theme will automatically be added to composer.json file by firing this plugin's commands after the specified commands
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @return void
	 */
	public function hooks()
	{
		\WP_CLI::add_hook('after_invoke:plugin install', function() {
			if ($this->run_hooks === true) {
				list($plugins_or_themes_slug, $args, $assoc_args) = $this->getCommandHookArgs();
				$this->setInstallerPath();
				$this->addPlugin($plugins_or_themes_slug, $args, $assoc_args);
			}
		});
		\WP_CLI::add_hook('after_invoke:plugin uninstall', function() {
			if ($this->run_hooks === true) {
				list($plugins_or_themes_slug, $args, $assoc_args) = $this->getCommandHookArgs();
				$this->setInstallerPath();
				$this->removePlugin($plugins_or_themes_slug, $args, $assoc_args);
			}
		});
		\WP_CLI::add_hook('after_invoke:plugin delete', function() {
			if ($this->run_hooks === true) {
				list($plugins_or_themes_slug, $args, $assoc_args) = $this->getCommandHookArgs();
				$this->setInstallerPath();
				$this->removePlugin($plugins_or_themes_slug, $args, $assoc_args);
			}
		});
		\WP_CLI::add_hook('after_invoke:theme install', function() {
			if ($this->run_hooks === true) {
				list($plugins_or_themes_slug, $args, $assoc_args) = $this->getCommandHookArgs();
				$this->setInstallerPath();
				$this->addTheme($plugins_or_themes_slug, $args, $assoc_args);
			}
		});
		\WP_CLI::add_hook('after_invoke:theme uninstall', function() {
			if ($this->run_hooks === true) {
				list($plugins_or_themes_slug, $args, $assoc_args) = $this->getCommandHookArgs();
				$this->setInstallerPath();
				$this->removeTheme($plugins_or_themes_slug, $args, $assoc_args);
			}
		});
		\WP_CLI::add_hook('after_invoke:theme delete', function() {
			if ($this->run_hooks === true) {
				list($plugins_or_themes_slug, $args, $assoc_args) = $this->getCommandHookArgs();
				$this->setInstallerPath();
				$this->removeTheme($plugins_or_themes_slug, $args, $assoc_args);
			}
		});
	}

	/**
	 * Passed the previous command arguments to the hooked functions
	 *
	 * WP-CLI doesn't pass the arguments used by the previous command when you're hooking into commands, so we need our own code to get the arguments used by the previous command.
	 *
	 * @link http://wp-cli.org/docs/internal-api/wp-cli-add-hook/
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @return array An associative array with plugins or themes to act upon, postional arguments passed to a WP-CLI command, and associative arguments passed to a WP-CLI command
	 */
	private function getCommandHookArgs()
	{
		global $argv;
		$cli_args = $argv;

		$cli_args = array_slice($cli_args, 1);
		// convert to wp-cli position arguments
		$configurator = \WP_CLI::get_configurator();
		list( $args, $assoc_args, $runtime_config ) = $configurator->parse_args( $cli_args );
		// get the plugin/theme name from the command line
		$plugins_or_themes_slug = [];
		for ($i = 2;$i < count($args);$i++) {
			$plugins_or_themes_slug[] = $args[$i];
		}

		return [$plugins_or_themes_slug,  $args, $assoc_args];
	}

	/**
	 * Perform commands on installed plugins.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : The command to perform. Available commands are:
	 * - "add" command will add installed plugins to composer.json
	 * - "install" command will install listed in composer.json
	 * - "deactivate" command will deactivate plugins listed in composer.json
	 * - "uninstall" command will deactivate, uninstall, and remove the plugins from composer.json
	 *
	 * [--file]
	 * : Path to save the composer.json file
	 *
	 * [--all]
	 * : Add plugins found on wordpress.org and plugins not found on wordpress.org. By default only plugins  available on wordpress.org will be added
	 *
	 * [--latest]
	 * : Add current version of plugin installed or specify to always use the latest version from whatever repo the plugin is coming from.
	 *
	 * [--installer-paths]
	 * : Set the WordPress plugins and themes installer path
	 *
	 * [--ip]
	 * : Set the WordPress plugins and themes installer path (shorter alias for "installer-paths")
	 *
	 * [--dev]
	 * : Only apply command to plugins defined as a dev requirement (only works with "uninstall" command at this moment)
	 *
	 * [--deactivate]
	 * : Deactivate the plugins before uninstalling them (this only applies to the deactivate command)
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to command
	 * @param array $assoc_args Key based arguments passed to command
	 * @return true|false True if able to write to a composer.json file, false if unable to write to the file for some reason
	 */
	public function plugins($args, $assoc_args = array())
	{
		if (isset($args[0])) {

			if (isset($assoc_args['installer-path']) || isset($assoc_args['ip'])) {
				if (isset($assoc_args['installer-path'])) {
					$installer_path = $assoc_args['installer-path'];
				} else {
					$installer_path = $assoc_args['ip'];
				}
				$this->composer->setInstallerPath( $installer_path );
			}

			switch($args[0]) {
				case "add":
					$this->addPlugins($args, $assoc_args);
					break;
				case "install":
					$this->installPlugins($args, $assoc_args);
					break;
				case "uninstall":
					$this->uninstallPlugins($args, $assoc_args);
					break;
				case "activate":
					$this->activatePlugins($args, $assoc_args);
					break;
				case "deactivate":
					$this->deactivatePlugins($args, $assoc_args);
					break;
				default:
					$message = sprintf('%s is not a valid command', $args[0]);
					\WP_CLI::error($message);
			}
		}
	}

	/**
	 * Perform commands on installed themes.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : The command to perform. Available commands are:
	 * - "add" command will add installed themes to composer.json
	 * - "install" command will install themes listed in the composer.json
	 * - "deactivate" command will deactivate themes listed in composer.json
	 * - "uninstall" command will uninstall and remove themes listed in the composer.json file
	 *
	 * [--file]
	 * : Path to save the composer.json file
	 *
	 * [--all]
	 * : Add themes found on wordpress.org and themes not found on wordpress.org. By default only themes available on wordpress.org will be added
	 *
	 * [--latest]
	 * : Add current version of theme installed or specify to always use the latest version from whatever repo the theme is coming from.
	 *
	 * [--installer-paths]
	 * : Set the WordPress plugins and themes installer path
	 *
	 * [--ip]
	 * : Set the WordPress plugins and themes installer path (shorter alias for "installer-paths")
	 *
	 * [--dev]
	 * : Only apply command to plugins defined as a dev requirement (only works with "uninstall" command at this moment)
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to command
	 * @param array $assoc_args Key based arguments passed to command
	 * @return true|false True if able to write to a composer.json file, false if unable to write to the file for some reason
	 */
	public function themes($args, $assoc_args = array())
	{
		if (isset($args[0])) {

			if (isset($assoc_args['installer-path']) || isset($assoc_args['ip'])) {
				if (isset($assoc_args['installer-path'])) {
					$installer_path = $assoc_args['installer-path'];
				} else {
					$installer_path = $assoc_args['ip'];
				}
				$this->composer->setInstallerPath( $installer_path );
			}

			switch($args[0]) {
				case "add":
					$this->addThemes($args, $assoc_args);
					break;
				case "install":
					$this->installThemes( $args, $assoc_args );
					break;
				case "uninstall":
					$this->uninstallThemes( $args, $assoc_args );
					break;
				case "activate":
					$this->activateThemes( $args, $assoc_args );
					break;
				case "deactivate":
					$this->deactivateThemes( $args, $assoc_args );
					break;
				default:
					$message = sprintf('%s is not a valid command', $args[0]);
					\WP_CLI::error($message);
			}
		}
	}

	/**
	 * Perform commands on a particular plugin by specifying the plugin slug.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : The command to perform. Available commands are:
	 * - "add" will add the specified plugin slug to the composer.json file
	 * - "remove" will remove the specified plugin from the composer.json file
	 *
	 * <plugin>...
	 * : One or more plugin slug. When using the plugin slug to specify the plugin, by default only a plugin available on wordpress.org will be added
	 *
	 * [--file]
	 * : Path to save the composer.json file
	 *
	 * [--latest]
	 * : Add current version of plugin installed or specify to always use the latest version from whatever repo the plugin is coming from.
	 *
	 * [--version]
	 * : Use the specified the version. This only applies when using the "add" command.
	 *
	 * [--dev]
	 * : Add or remove the plugin as a dev dependency
	 *
	 * [--installer-paths]
	 * : Set the WordPress plugins and themes installer path
	 *
	 * [--ip]
	 * : Set the WordPress plugins and themes installer path (shorter alias for "installer-paths")
	 *
	 * [--no-verify]
	 * : Skip verifying that a plugin is available on Wordpress.org before adding it to the composer.json file. By default, only plugins currently available on Wordpress.org will be added to composer.json
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to the command
	 */
	public function plugin($args, $assoc_args = array())
	{
		if ( isset( $args[1] ) ) {
			// get the list of plugins to act upon
			$plugin_slug = [];
			$plugin_count = count($args);
			for ($i = 1;$i < $plugin_count;$i++) {
				$plugin_slug[] = $args[$i];
			}

			if (isset($assoc_args['installer-path']) || isset($assoc_args['ip'])) {
				if (isset($assoc_args['installer-path'])) {
					$installer_path = $assoc_args['installer-path'];
				} else {
					$installer_path = $assoc_args['ip'];
				}
				$this->composer->setInstallerPath( $installer_path );
			}

			if ( isset( $args[0] ) ) {

				switch ( $args[0] ) {
					case "add":
						$this->addPlugin( $plugin_slug, $args, $assoc_args );
						break;
					case "remove":
						$this->removePlugin( $plugin_slug, $args, $assoc_args );
						break;
					default:
						$message = sprintf( '%s is not a valid command', $args[0] );
						\WP_CLI::error( $message );
				}
			}
		}
	}

	/**
	 * Perform commands on a particular theme by specifying the theme slug.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : The command to perform. Available commands are:
	 * - "add" will add the specified theme slug to the composer.json file
	 * - "remove" will remove the specified theme from the composer.json file
	 *
	 * <theme>...
	 * : One or more theme slugs. When using the theme slug to specify the theme, by default only a theme available on wordpress.org will be added
	 *
	 * [--file]
	 * : Path to save the composer.json file
	 *
	 * [--latest]
	 * : Add current version of theme installed or specify to always use the latest version from whatever repo the plugin is coming from.
	 *
	 * [--version]
	 * : Use the specified the version. This only applies when using the "add" command.
	 *
	 * [--dev]
	 * : Add or remove the theme as a dev dependency
	 *
	 * [--installer-paths]
	 * : Set the WordPress plugins and themes installer path
	 *
	 * [--ip]
	 * : Set the WordPress plugins and themes installer path (shorter alias for "installer-paths")
	 *
	 * [--activate]
	 * : Activate the plugins defined in composer.json (only applies to the "add" command)
	 *
	 * [--no-verify]
	 * : Skip verifying that a theme is available on Wordpress.org before adding it to the composer.json file. By default, only themes currently available on Wordpress.org are will be added to the composer.json file
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to the command
	 */
	public function theme($args, $assoc_args = array())
	{
		if ( isset( $args[1] ) ) {
			// get the list of themes to act upon
			$theme_slug = [];
			$theme_count = count($args);
			for ($i = 1;$i < $theme_count;$i++) {
				$theme_slug[] = $args[$i];
			}

			if (isset($assoc_args['installer-path']) || isset($assoc_args['ip'])) {
				if (isset($assoc_args['installer-path'])) {
					$installer_path = $assoc_args['installer-path'];
				} else {
					$installer_path = $assoc_args['ip'];
				}
				$this->composer->setInstallerPath( $installer_path );
			}

			if ( isset( $args[0] ) ) {
				switch ( $args[0] ) {
					case "add":
						$this->addTheme( $theme_slug, $args, $assoc_args );
						break;
					case "remove":
						$this->removeTheme( $theme_slug, $args, $assoc_args );
						break;
					default:
						$message = sprintf( '%s is not a valid command', $args[0] );
						\WP_CLI::error( $message );
				}
			}
		}
	}

	/**
	 * Add installed plugins and themes to composer.json.
	 *
	 * ## OPTIONS
	 *
	 * [--file]
	 * : Path to save the composer.json file
	 *
	 * [--all]
	 * : Add themes and plugins found on wordpress.org and themes and plugins not found on wordpress.org. By default only themes and plugins available on wordpress.org will be added
	 *
	 * [--latest]
	 * : Add current version of theme or plugin installed or specify to always use the latest version from whatever repo the theme or plugn is coming from.
	 *
	 * [--installer-paths]
	 * : Set the WordPress plugins and themes installer path
	 *
	 * [--ip]
	 * : Set the WordPress plugins and themes installer path (shorter alias for "installer-paths")
	 *
	 * [--no-verify]
	 * : Skip verifying that a plugin/theme is available on Wordpress.org before adding it to the composer.json file. By default, only plugins/themes currently available on Wordpress.org will be added to composer.json
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to command
	 * @param array $assoc_args Key based arguments passed to command
	 * @return true|false True if able to write to a composer.json file, false if unable to write to the file for some reason
	 */
	public function addAllDependencies($args, array $assoc_args = array())
	{
		if (isset($assoc_args['installer-path']) || isset($assoc_args['ip'])) {
			if (isset($assoc_args['installer-path'])) {
				$installer_path = $assoc_args['installer-path'];
			} else {
				$installer_path = $assoc_args['ip'];
			}
			$this->composer->setInstallerPath( $installer_path );
		}
		
		\WP_CLI::run_command(array('composer', 'plugins', 'add'), $assoc_args);
		\WP_CLI::run_command(array('composer', 'themes', 'add'), $assoc_args);
	}

	/**
	 * Install the dependencies of themes and plugins
	 *
	 * If a theme or plugin has a composer.json, attempt to install those dependencies if they are not currently installed.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : Type of WordPress asset to install. The types are:
	 * - "plugins" Will install the composer dependencies of plugins
	 * - "themes" Will install the composer dependencies of plugins
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function installDependencies($args, $assoc_args)
	{
		if (isset($args[0])) {
			switch ($args[0]) {
				case "plugins":
					$this->installPluginsDependencies($args, $assoc_args);
					break;
				case "themes":
					$this->installThemesDependencies($args, $assoc_args);
					break;
				default:
					$message = sprintf( '%s is not a valid command', $args[0] );
					\WP_CLI::error( $message );
			}
		}
	}


	/**
	 * Add installed plugins to composer.json.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to command
	 * @param array $assoc_args Key based arguments passed to command
	 * @return true|false True if able to write to a composer.json file, false if unable to write to the file for some reason
	 */
	private function addPlugins(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$all = false;
		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['all'])) {
			$all = true;
		}

		if (isset($assoc_args['latest'])) {
			$latest_version = '*';
		}

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);

		if (!isset($assoc_args['plugins'])) {
			ob_start();
			\WP_CLI::run_command(array('plugin', 'list'), array('format'=>'json'));
			$installed_plugins = ob_get_clean();
		}

		$plugins_found = json_decode($installed_plugins, true);

		$plugins_added = array();

		if (!empty($composer) && is_array($plugins_found)) {
			foreach ($plugins_found as $plugin) {
				if ($all === false) {
					if ($this->composer->isPluginAvailable($plugin['name'])) {
						$plugins_added[] = $plugin['name'];
						if (isset($latest_version)) {
							$plugin_version = $latest_version;
						} else {
							$plugin_version = $plugin['version'];
						}
						\WP_CLI::line(sprintf('Adding plugin %s. Using version %s',$plugin['name'], $plugin_version));
						$this->composer->addPluginDependency($plugin['name'], $plugin_version);
					}
				} else {
					$plugins_added[] = $plugin['name'];
					if (isset($latest_version)) {
						$plugin_version = $latest_version;
					} else {
						$plugin_version = $plugin['version'];
					}
					\WP_CLI::line(sprintf('Adding plugin %s. Using version %s',$plugin['name'], $plugin_version));
					$this->composer->addPluginDependency($plugin['name'], $plugin_version);
				}
			}

			try {
				$success = $this->composer->saveComposer($file);
				$saved_file = $file;
				if (empty($saved_file)) {
					$saved_file = $this->composer->composer_file_location;
				}

				$plugins_added = implode(', ', $plugins_added);

				if ($success === true) {
					\WP_CLI::success(sprintf('Saved %s plugin dependencies to %s', $plugins_added, $saved_file));
					return true;
				}
			} catch (\Exception $e) {
				\WP_CLI::warning($e->getMessage());
				return false;
			}
		}

	}

	/**
	 * Add installed themes to composer.json
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to command
	 * @param array $assoc_args Key based arguments passed to command
	 * @return true|false True if able to write to a composer.json file, false if unable to write to the file for some reason
	 */
	private function addThemes(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$all = false;
		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['all'])) {
			$all = true;
		}

		if (isset($assoc_args['latest'])) {
			$latest_version = '*';
		}

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		ob_start();
		\WP_CLI::run_command(array('theme', 'list'), array('format'=>'json'));
		$themes_found = json_decode(ob_get_clean(), true);
		$themes_added = array();

		if (!empty($composer) && is_array($themes_found)) {
			foreach ($themes_found as $theme) {
				if ($all === false) {
					if ($this->composer->isThemeAvailable($theme['name'])) {
						$themes_added[] = $theme['name'];
						if (isset($latest_version)) {
							$theme_version = $latest_version;
						} else {
							$theme_version = $theme['version'];
						}
						\WP_CLI::line(sprintf('Adding theme %s. Using version %s',$theme['name'], $theme_version));
						$this->composer->addThemeDependency($theme['name'], $theme_version);
					}
				} else {
					$themes_added[] = $theme['name'];
					if (isset($latest_version)) {
						$theme_version = $latest_version;
					} else {
						$theme_version = $theme['version'];
					}
					\WP_CLI::line(sprintf('Adding theme %s. Using version %s',$theme['name'], $theme_version));
					$this->composer->addThemeDependency($theme['name'], $theme_version);
				}
			}

			try {
				$success = $this->composer->saveComposer($file);
				$saved_file = $file;
				if (empty($saved_file)) {
					$saved_file = $this->composer->composer_file_location;
				}

				$themes_added = implode(', ', $themes_added);

				if ($success === true) {
					\WP_CLI::success(sprintf('Saved %s theme dependencies to %s', $themes_added, $saved_file));
					return true;
				}
			} catch (\Exception $e) {
				\WP_CLI::warning($e->getMessage());
				return false;
			}
		}
	}

	/**
	 * Add the specified plugin to the composer.json file.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param string|array $plugin_slug One or more plugin slug or path to download the plugin from.
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return bool True if the plugin was successfully added to composer.json. False, if error writing to composer.json file
	 */
	private function addPlugin($plugin_slug, array $args = [], array $assoc_args = [])
	{
		if (is_string($plugin_slug)) {
			$plugin_slug = [$plugin_slug];
		}

		if (is_array($plugin_slug)) {
			$file = '';
			$plugin_version = '*';
			$dev = false;
			$verify = true;
			$skipped = [];

			if (isset($assoc_args['latest'])) {
				$plugin_version = '*';
			}

			// disregard the version argument if the user tries to add more than one plugin since not all plugins have the same version number
			if (isset($assoc_args['version']) && count($plugin_slug) === 1) {
				$plugin_version = $assoc_args['version'];
			}

			if (isset($assoc_args['file'])) {
				$file = $assoc_args['file'];
			}

			if (isset($assoc_args['dev'])) {
				$dev = true;
			}

			if (isset($args['no-verify'])) {
				$verify = false;
			}

			$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);

			if ( !empty( $composer ) ) {

				foreach ($plugin_slug as $plugin) {
					if ($verify !== false) {

						if ($this->composer->isPluginAvailable($plugin) === false) {
							$skipped[] = $plugin;
							unset($plugin_slug[key($plugin_slug)]);
						}
					}

					if (!in_array($plugin, $skipped)) {
						\WP_CLI::line(sprintf('Adding plugin %s. Using version %s', $plugin, $plugin_version));
						if ($dev) {
							$this->composer->addDevPluginDependency($plugin, $plugin_version);
						} else {
							$this->composer->addPluginDependency($plugin, $plugin_version);
						}
					}
				}

				try {
					$success = $this->composer->saveComposer( $file );

					$saved_file = $file;
					if ( empty( $saved_file ) ) {
						$saved_file = $this->composer->composer_file_location;
					}

					if (isset($plugin_slug[0])) {
						if ($dev) {
							$message = sprintf('Saved %s plugin as a dev dependency to %s', implode(', ', $plugin_slug), $saved_file);
						} else {
							$message = sprintf('Saved %s plugin as a dependency to %s', implode(', ', $plugin_slug), $saved_file);
						}
					}

					if ( $success === true ) {
						if (isset($plugin_slug[0])) {
							\WP_CLI::success($message);
						}

						if (isset($skipped[0])) {
							\WP_CLI::warning(sprintf('Unable to add %s as dependency to composer.json. The plugin was not found on Wordpress.org', implode(', ', $skipped)));
						}

						return true;
					}
				} catch ( \Exception $e ) {
					\WP_CLI::warning( $e->getMessage() );

					return false;
				}
			}
		}
	}

	/**
	 * Add the specified theme to the composer.json file.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param string|array $theme_slug One or more theme slugs or path to download the theme from.
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return bool True if the theme was successfully added to composer.json. False, if error writing to composer.json file
	 */
	private function addTheme($theme_slug, array $args = [], array $assoc_args = [])
	{
		if (is_string($theme_slug)) {
			$theme_slug = [$theme_slug];
		}

		if (is_array($theme_slug)) {
			$file = '';
			$theme_version = '*';
			$dev = false;
			$verify = true;
			$skipped = [];

			if (isset($assoc_args['file'])) {
				$file = $assoc_args['file'];
			}

			if (isset($assoc_args['latest'])) {
				$theme_version = '*';
			}

			// disregard the version argument if the user tries to add more than one theme since not all theme have the same version number
			if (isset($assoc_args['version']) && count($theme_slug) === 1) {
				$theme_version = $assoc_args['version'];
			}

			if (isset($assoc_args['dev'])) {
				$dev = true;
			}

			if (isset($args['no-verify'])) {
				$verify = false;
			}

			$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);

			if ( !empty( $composer ) ) {

				foreach ($theme_slug as $theme) {

					if ($verify !== false) {

						if ($this->composer->isThemeAvailable($theme) === false) {
							$skipped[] = $theme;
							unset($theme_slug[key($theme_slug)]);
						}
						// themes hosted on WordPress.org don't have versioning numbers that we can ping easily the same way plugins have, so always use the latest version of a theme since when we attempt to install a certain theme version using composer, an error may be thrown saying the theme version cannot be found
						if ($theme_version !== '*') {
							$theme_version = sprintf('^%s', $theme_version);
						}
					}

					if (!in_array($theme, $skipped)) {
						\WP_CLI::line(sprintf('Adding theme %s. Using version %s', $theme, $theme_version));
						if ($dev) {
							$this->composer->addDevThemeDependency($theme, $theme_version);
						} else {
							$this->composer->addThemeDependency($theme, $theme_version);
						}
					}
				}

				try {
					$success = $this->composer->saveComposer( $file );

					$saved_file = $file;
					if ( empty( $saved_file ) ) {
						$saved_file = $this->composer->composer_file_location;
					}

					if ( $success === true ) {
						if ($dev) {
							$message = sprintf( 'Saved %s theme as a dev dependency to %s', implode(', ', $theme_slug), $saved_file );
						} else {
							$message = sprintf( 'Saved %s theme as a dependency to %s', implode(', ', $theme_slug), $saved_file );
						}

						\WP_CLI::success( $message );
						if (isset($skipped[0])) {
							\WP_CLI::warning(sprintf('Unable to add %s as dependency to composer.json. The theme was not found on Wordpress.org', implode(', ', $skipped)));
						}

						return true;
					}
				} catch ( \Exception $e ) {
					\WP_CLI::warning( $e->getMessage() );

					return false;
				}
			}
		}

	}

	/**
	 * Remove the specified plugin from the composer.json file.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param string|array $plugin_slug One or more plugins to remove as dependencies
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return bool True if the plugin was successfully removed from composer.json. False, if error writing to composer.json file
	 */
	private function removePlugin($plugin_slug, array $args = [], array $assoc_args = [])
	{

		if (is_string($plugin_slug)) {
			$plugin_slug = [$plugin_slug];
		}

		if (is_array($plugin_slug)) {
			$file = '';
			$dev = false;
			if (isset($assoc_args['file'])) {
				$file = $assoc_args['file'];
			}

			if (isset($args['dev'])) {
				$dev = true;
			}
			$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);

			if (!empty($composer)) {

				foreach ($plugin_slug as $plugin) {
					if ($dev) {
						\WP_CLI::line(sprintf('Removing plugin %s as dev dependency', $plugin));
						$this->composer->removeDevPluginDependency($plugin);
					} else {
						\WP_CLI::line(sprintf('Removing plugin %s as dependency', $plugin));
						$this->composer->removePluginDependency($plugin);
					}
				}

				try {
					$success = $this->composer->saveComposer($file);
					$saved_file = $file;
					if (empty($saved_file)) {
						$saved_file = $this->composer->composer_file_location;
					}

					if ($success === true) {
						\WP_CLI::success(sprintf('Removed %s plugin dependency from %s', implode(', ', $plugin_slug), $saved_file));

						return true;
					}
				} catch (\Exception $e) {
					\WP_CLI::warning($e->getMessage());

					return false;
				}
			}
		}
	}

	/**
	 * Remove the specified theme from the composer.json file.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param string|array $theme_slug One or more theme slugs to remove as dependencies
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return bool True if the theme was successfully removed from composer.json. False, if error writing to composer.json file
	 */
	private function removeTheme($theme_slug, array $args = [], array $assoc_args = [])
	{

		if (is_string($theme_slug)) {
			$theme_slug = [$theme_slug];
		}

		if (is_array($theme_slug)) {
			$file = '';
			$dev = false;
			if (isset($assoc_args['file'])) {
				$file = $assoc_args['file'];
			}

			if (isset($args['dev'])) {
				$dev = true;
			}

			$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);

			if (!empty($composer)) {
				foreach ($theme_slug as $theme) {
					if ($dev) {
						\WP_CLI::line(sprintf('Removing theme %s as dev dependency', $theme));
						$this->composer->removeDevPluginDependency($theme);
					} else {
						\WP_CLI::line(sprintf('Removing theme %s as dependency', $theme));
						$this->composer->removeThemeDependency($theme);
					}
				}

				try {
					$success = $this->composer->saveComposer($file);
					$saved_file = $file;
					if (empty($saved_file)) {
						$saved_file = $this->composer->composer_file_location;
					}

					if ($success === true) {
						\WP_CLI::success(sprintf('Removed %s theme dependency from %s', implode(', ', $theme_slug), $saved_file));

						return true;
					}
				} catch (\Exception $e) {
					\WP_CLI::warning($e->getMessage());

					return false;
				}
			}
		}
	}

	/**
	 * Bulk activate plugins in composer.json
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function activatePlugins(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_plugin = 'require';

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_plugin = 'require-dev';
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_plugin])) {
			$installed_plugins = array();
			foreach ($composer[$type_of_plugin] as $plugin_name=>$version) {
				if ($this->composer->isWordPressPlugin($plugin_name)) {
					$plugin_slug = $this->composer->removeNamespace($plugin_name, 'plugin');
					$installed_plugins[] = $plugin_slug;
				}
			}

			if (!empty($installed_plugins)) {
				\WP_CLI::run_command(['plugin', 'activate', implode(' ', $installed_plugins)], [], $global_args);
			}
		}
	}

	/**
	 * Bulk activate themes in composer.json
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function activateThemes(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_theme = 'require';

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_theme = 'require-dev';
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_theme])) {
			$activate_themes = array();
			foreach ($composer[$type_of_theme] as $theme_name=>$version) {
				if ($this->composer->isWordPressTheme($theme_name)) {
					$theme_slug = $this->composer->removeNamespace($theme_name, 'theme');
					$activate_themes[] = $theme_slug;
				}
			}

			if (!empty($activate_themes)) {
				\WP_CLI::run_command(['plugin', 'activate', implode(' ', $activate_themes)], [], $global_args);
			}
		}
	}

	/**
	 * Bulk deactivate plugins in composer.json
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function deactivatePlugins(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_plugin = 'require';

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_plugin = 'require-dev';
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_plugin])) {
			$deactivate_plugins = array();
			foreach ($composer[$type_of_plugin] as $plugin_name=>$version) {
				if ($this->composer->isWordPressPlugin($plugin_name)) {
					$plugin_slug = $this->composer->removeNamespace($plugin_name, 'plugin');
					$deactivate_plugins[] = $plugin_slug;
				}
			}

			if (!empty($deactivate_plugins)) {
				\WP_CLI::run_command(['plugin', 'deactivate', implode(' ', $deactivate_plugins)], [], $global_args);
			}
		}
	}

	/**
	 * Bulk deactivate themes in composer.json
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function deactivateThemes(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_theme = 'require';

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_theme = 'require-dev';
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_theme])) {
			$deactivate_themes = array();
			foreach ($composer[$type_of_theme] as $theme_name=>$version) {
				if ($this->composer->isWordPressTheme($theme_name)) {
					$theme_slug = $this->composer->removeNamespace($theme_name, 'theme');
					$deactivate_themes[] = $theme_slug;
				}
			}

			if (!empty($deactivate_themes)) {
				\WP_CLI::run_command(['plugin', 'deactivate', implode(' ', $deactivate_themes)], [], $global_args);
			}
		}
	}

	/**
	 * Bulk uninstall plugins
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return bool True if the plugins we're uninstalled successfully and we were able to remove the plugin from composer.json. False, if error writing to composer.json file
	 */
	private function uninstallPlugins(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_plugin = 'require';
		$dev = false;

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_plugin = 'require-dev';
			$dev = true;
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_plugin])) {
			$found_plugins = array();
			foreach ($composer[$type_of_plugin] as $plugin_name=>$version) {
				if ($this->composer->isWordPressPlugin($plugin_name)) {
					$plugin_slug = $this->composer->removeNamespace($plugin_name, 'plugin');
					$found_plugins[] = $plugin_slug;

					if ($dev) {
						$this->composer->removeDevPluginDependency( $plugin_slug );
					} else {
						$this->composer->removePluginDependency( $plugin_slug );
					}
				}
			}

			if (!empty($found_plugins)) {
				$this->run_hooks = false;
				try {
					$success = $this->composer->saveComposer( $file );
					$saved_file = $file;
					if ( empty( $saved_file ) ) {
						$saved_file = $this->composer->composer_file_location;
					}

					$uninstalled_plugins = implode(' ', $found_plugins);
					if (isset($assoc_args['deactivate'])) {
						\WP_CLI::run_command(['plugin', 'deactivate', $uninstalled_plugins], [], $global_args);
					}
					\WP_CLI::run_command(['plugin', 'uninstall', $uninstalled_plugins], [], $global_args);
					$uninstalled_plugins = implode(', ', $found_plugins);

					if ( $success === true ) {
						if ($dev) {
							$message = sprintf( 'Uninstalled plugin(s) %s and removed dev plugin dependency from %s', $uninstalled_plugins, $saved_file );
						} else {
							$message = sprintf( 'Uninstalled plugin(s) %s and removed plugin dependency from %s', $uninstalled_plugins, $saved_file );
						}

						\WP_CLI::success( $message );

						return true;
					}
				} catch ( \Exception $e ) {
					\WP_CLI::warning( $e->getMessage() );

					return false;
				}
			}
		}
	}

	/**
	 * Bulk uninstall themes
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return bool True if the themes we're uninstalled successfully and we were able to remove the theme from composer.json. False, if error writing to composer.json file
	 */
	private function uninstallThemes(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_theme = 'require';
		$dev = false;

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_theme = 'require-dev';
			$dev = true;
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_theme])) {
			$found_themes = array();
			foreach ($composer[$type_of_theme] as $theme_name=>$version) {
				if ($this->composer->isWordPressTheme($theme_name)) {
					$theme_slug = $this->composer->removeNamespace($theme_name, 'theme');
					$found_themes[] = $theme_slug;

					if ($dev) {
						$this->composer->removeDevThemeDependency( $theme_slug );
					} else {
						$this->composer->removeThemeDependency( $theme_slug );
					}
				}
			}

			if (!empty($found_themes)) {
				$this->run_hooks = false;
				try {

					$success = $this->composer->saveComposer( $file );
					$saved_file = $file;
					if ( empty( $saved_file ) ) {
						$saved_file = $this->composer->composer_file_location;
					}

					$uninstalled_themes = implode(' ', $found_themes);
					if (isset($assoc_args['deactivate'])) {
						\WP_CLI::run_command(['theme', 'deactivate', $uninstalled_themes], [], $global_args);
					}
					\WP_CLI::run_command(['theme', 'uninstall', $uninstalled_themes], [], $global_args);
					$uninstalled_themes = implode(', ', $found_themes);

					if ( $success === true ) {
						if ($dev) {
							$message = sprintf( 'Uninstalled theme %s and removed dev theme dependency from %s', $uninstalled_themes, $saved_file );
						} else {
							$message = sprintf( 'Uninstalled theme %s and removed theme dependency from %s', $uninstalled_themes, $saved_file );
						}

						\WP_CLI::success( $message );

						return true;
					}
				} catch ( \Exception $e ) {
					\WP_CLI::warning( $e->getMessage() );

					return false;
				}
			}

		}
	}

	/**
	 * Bulk install plugins
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function installPlugins(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_plugin = 'require';
		$dev = false;

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_plugin = 'require-dev';
			$dev = true;
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_plugin])) {
			$found_plugins = array();
			$this->run_hooks = false;
			foreach ($composer[$type_of_plugin] as $plugin_name => $version) {
				if ($this->composer->isWordPressPlugin($plugin_name)) {
					$plugin_slug = $this->composer->removeNamespace($plugin_name, 'plugin');
					$found_plugins[] = $plugin_slug;

					// since wordpress.org doesn't label plugins by these words, these are all alias to download the latest version
					$bad_versions_of_plugins = ['*', 'dev-trunk', 'dev-master', 'master', 'dev'];
					if (in_array($version, $bad_versions_of_plugins)) {
						\WP_CLI::run_command(['plugin', 'install', $plugin_slug], [], $global_args);
					} else {
						\WP_CLI::run_command(['plugin', 'install', $plugin_slug], ['version' => $version], $global_args);
					}

				}
			}

			if (!empty($found_plugins)) {

				$saved_file = $file;
				if ( empty( $saved_file ) ) {
					$saved_file = $this->composer->composer_file_location;
				}

				$installed_plugins = implode(', ', $found_plugins);

				if ($dev) {
					$message = sprintf( 'Successfully installed required dev plugins %s found in %s', $installed_plugins, $saved_file );
				} else {
					$message = sprintf( 'Successfully installed required plugins %s found in %s', $installed_plugins, $saved_file );
				}

				\WP_CLI::success( $message );
			}

		}
	}

	/**
	 * Bulk install themes
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array $args Positional arguments passed to the command
	 * @param array $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function installThemes(array $args = [], array $assoc_args = [])
	{
		$file = '';
		$type_of_theme = 'require';
		$dev = false;

		if (isset($assoc_args['file'])) {
			$file = $assoc_args['file'];
		}

		if (isset($assoc_args['dev'])) {
			$type_of_theme = 'require-dev';
			$dev = true;
		}

		unset($assoc_args['dev']);
		unset($assoc_args['file']);

		$global_args = $assoc_args;

		$composer = json_decode(json_encode($this->composer->readComposerFile($file)), true);
		if (isset($composer[$type_of_theme])) {
			$found_themes = array();
			$this->run_hooks = false;
			foreach ($composer[$type_of_theme] as $theme_name=>$version) {
				if ($this->composer->isWordPressTheme($theme_name)) {
					$theme_slug = $this->composer->removeNamespace($theme_name, 'theme');
					$found_themes[] = $theme_slug;

					// since wordpress.org doesn't label themes by these words, these are all alias to download the latest version
					$bad_versions_of_themes = ['*','dev-trunk','dev-master','master','dev'];
					if (in_array($version, $bad_versions_of_themes)) {
						\WP_CLI::run_command(['theme', 'install', $theme_slug], [], $global_args);
					} else {
						\WP_CLI::run_command(['theme', 'install', $theme_slug], ['version'=>$version], $global_args);
					}

				}
			}

			if (!empty($found_themes)) {

				$saved_file = $file;
				if ( empty( $saved_file ) ) {
					$saved_file = $this->composer->composer_file_location;
				}
				$installed_themes = implode(', ', $found_themes);
				if ($dev) {
					$message = sprintf( 'Successfully installed required dev themes %s found in %s', $installed_themes, $saved_file );
				} else {
					$message = sprintf( 'Successfully installed required themes %s found in %s', $installed_themes, $saved_file );
				}

				\WP_CLI::success( $message );
			}

		}
	}

	/**
	 * Bulk install composer dependencies for WordPress plugins
	 *
	 * If a plugin has a composer.json file, check if it's dependencies are already installed. If not, run "composer install" command
	 *
	 * @since 1.0.0
	 * @version 1.0.21
	 *
	 * @param $args Positional arguments passed to the command
	 * @param $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function installPluginsDependencies($args, $assoc_args)
	{
		if (class_exists('\\Composer\\Console\\Application')) {
			$plugin_type_folders = ['mu-plugins', 'plugins'];
			foreach ($plugin_type_folders as $plugin_type) {
				$plugins_path = $this->getAssetsPath() . '/'. $plugin_type;
				$plugins = glob($plugins_path . '/*', GLOB_ONLYDIR);
				if (!empty($plugins)) {
					foreach ($plugins as $plugin) {
						// don't try to install dependencies for this plugin
						if ($plugin === dirname(dirname(__FILE__))) {
							continue;
						}

						$composer_file = $plugin . '/composer.json';
						if (file_exists($composer_file)) {
							// test if the plugin already has it's dependencies installed
							$read_composer = json_decode(file_get_contents($composer_file), true);
							$vendor_dir = $plugin . '/vendor';
							if (isset($read_composer['config']) && isset($read_composer['config']['vendor-dir'])) {
								$vendor_dir = $plugin . '/' . $read_composer['config']['vendor-dir'];
							}

							$plugin_folder = basename($plugin, 1);
							if (!empty($read_composer['require'])) {
								if (!is_dir($vendor_dir)) {
									\WP_CLI::line(sprintf('Installing dependencies for %s plugin...', $plugin_folder));
									@ob_end_clean();
									@ob_flush();
									$working_directory = sprintf('--working-dir=%s', $plugin);
									$_SERVER['argv'] = array('composer', 'update', $working_directory, '--no-dev');
									$composer = new \Composer\Console\Application();
									$composer->setAutoExit(false);
									$composer->run();
								}
							} else {
								\WP_CLI::line(sprintf('Skipping installing dependencies for %s plugin. No require packages listed.', $plugin_folder));
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Bulk install composer dependencies for WordPress themes
	 *
	 * If a plugin has a composer.json file, check if it's dependencies are already installed. If not, run "composer install" command
	 *
	 * @since 1.0.0
	 * @versio 1.0.0
	 *
	 * @param $args Positional arguments passed to the command
	 * @param $assoc_args Key based arguments passed to command
	 *
	 * @return void
	 */
	private function installThemesDependencies($args, $assoc_args)
	{
		if (class_exists('\\Composer\\Console\\Application')) {
			$themes_path = $this->getAssetsPath() . '/themes';
			$themes = glob($themes_path . '/*', GLOB_ONLYDIR);
			if (!empty($themes)) {
				foreach ($themes as $theme) {
					$composer_file = $theme . '/composer.json';
					if (file_exists($composer_file)) {
						// test if the plugin already has it's dependencies installed
						$read_composer = json_decode(file_get_contents($composer_file), true);
						$vendor_dir = $theme.'/vendor';
						if (isset($read_composer['config']) && isset($read_composer['config']['vendor-dir'])) {
							$vendor_dir = $theme.'/'.$read_composer['config']['vendor-dir'];
						}


						if (!empty($read_composer['require'])) {
							if (!is_dir($vendor_dir)) {
								$theme_folder = basename($theme, 1);
								\WP_CLI::line(sprintf('Installing dependencies for %s theme...', $theme_folder));
								@ob_end_clean();
								@ob_flush();
								$working_directory = sprintf('--working-dir=%s', $theme);
								$_SERVER['argv'] = array('composer', 'update', $working_directory, '--no-dev');
								$composer = new \Composer\Console\Application();
								$composer->setAutoExit(false);
								$composer->run();
							}
						}  else {
							\WP_CLI::line(sprintf('Skipping installing dependencies for %s theme. No require packages listed. ', $plugin_folder));
						}
					}
				}
			}
		}
	}

	/**
	 * Set the WordPress plugin and theme installer paths
	 *
	 * @since 1.0..0
	 * @version 1.0.0
	 *
	 * @return void
	 */
	private function setInstallerPath()
	{
		$installer_path = basename($this->getAssetsPath(), 2);
		$this->composer->setInstallerPath($installer_path);
	}

	/**
	 * Get the WordPress plugin install path
	 *
	 * @since 1.0.0
	 * @version 1.0.1
	 *
	 * @var $path string custom assets path
	 *
	 * @return string Path to the parent directory of the plugins and themes path
	 */
	public function getAssetsPath($path = '')
	{
		if (empty($path)) {
			$path = \WP_CLI::runcommand( 'plugin path', [ 'return' => true, 'launch' => true, 'exit_error' => true, 'parse' => false ] );
		}

		return @dirname($path);
	}
}