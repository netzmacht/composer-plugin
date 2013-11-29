<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Dominik Zogg <dominik.zogg@gmail.com>
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author  Tristan Lins <tristan.lins@bit3.de>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\ComposerInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ArtifactRepository;
use Composer\Repository\ComposerRepository;
use Composer\Util\Filesystem;

/**
 * Installer that install Contao extensions via shadow copies or symlinks
 * into the Contao file hierarchy.
 */
class Plugin
	implements PluginInterface, EventSubscriberInterface
{
	/**
	 * @var Composer
	 */
	protected $composer;

	/**
	 * @var IOInterface
	 */
	protected $io;

	/**
	 * {@inheritdoc}
	 */
	public function activate(Composer $composer, IOInterface $io)
	{
		$this->composer = $composer;
		$this->io       = $io;

		$installationManager = $composer->getInstallationManager();

		$installer = new ModuleInstaller($io, $composer);
		$installationManager->addInstaller($installer);

		$this->injectRequires();
		$this->addLocalArtifactsRepository();
		$this->addLegacyPackagesRepository();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			PluginEvents::COMMAND           => 'handleCommand',
			PluginEvents::PRE_FILE_DOWNLOAD => 'handlePreDownload'
		);
	}

	/**
	 * Inject the requirements into the root package.
	 */
	public function injectRequires()
	{
		$package  = $this->composer->getPackage();
		$requires = $package->getRequires();

		if (!isset($requires['contao-community-alliance/composer'])) {
			$requires['contao-community-alliance/composer'] = '*';
			$package->setRequires($requires);
		}
	}

	/**
	 * Add the local artifacts repository to the composer installation.
	 *
	 * @param Composer $composer The composer instance.
	 *
	 * @return void
	 */
	public function addLocalArtifactsRepository()
	{
		$contaoRoot = static::getContaoRoot($this->composer->getPackage());
		$artifactRepositoryPath = $contaoRoot . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'packages';
		if (is_dir($artifactRepositoryPath)) {
			$artifactRepository = new ArtifactRepository(array('url' => $artifactRepositoryPath), $this->io);
			$this->composer->getRepositoryManager()->addRepository($artifactRepository);
		}
	}

	/**
	 * Add the legacy Contao packages repository to the composer installation.
	 *
	 * @param Composer $composer The composer instance.
	 *
	 * @return void
	 */
	public function addLegacyPackagesRepository()
	{
		$legacyPackagistRepository = new ComposerRepository(
			array('url' => 'http://legacy-packages-via.contao-community-alliance.org/'),
			$this->io,
			$this->composer->getConfig(),
			$this->composer->getEventDispatcher()
		);
		$this->composer->getRepositoryManager()->addRepository($legacyPackagistRepository);
	}

	/**
	 * Handle command events.
	 *
	 * @param CommandEvent $event
	 */
	public function handleCommand(CommandEvent $event)
	{
		switch ($event->getCommandName())
		{
			case 'pre-update-cmd':

				ConfigManipulator::run($this->io, $this->composer);
				break;
			case 'post-update-cmd':
				$package = $this->composer->getPackage();
				$root = Plugin::getContaoRoot($package);

				$this->createRunonce($this->io, $root);
				$this->cleanCache($this->io, $root);
				break;
			case 'post-autoload-dump':
				ModuleInstaller::postAutoloadDump($event);
				break;
		}
	}

	/**
	 * Create the global runonce.php after updates has been installed.
	 *
	 * @param IOInterface $io
	 * @param string      $root The contao installation root.
	 */
	public function createRunonce(IOInterface $io, $root)
	{
		RunonceManager::createRunonce($io, $root);
	}

	/**
	 * Clean the internal cache of Contao after updates has been installed.
	 *
	 * @param IOInterface $io
	 * @param string      $root The contao installation root.
	 */
	public function cleanCache(IOInterface $io, $root)
	{
		// clean cache
		$fs = new Filesystem();
		foreach (array('config', 'dca', 'language', 'sql') as $dir) {
			$cache = $root . '/system/cache/' . $dir;
			if (is_dir($cache)) {
				$io->write(
					sprintf(
						'<info>Clean contao internal %s cache</info>',
						$dir
					)
				);
				$fs->removeDirectory($cache);
			}
		}
	}

	/**
	 * @draft
	 */
	public function handlePreDownload(PreFileDownloadEvent $event)
	{
		// TODO: handle the pre download event.
	}

	/**
	 * Detect the contao installation root and set the TL_ROOT constant
	 * if not already exist (from previous run or when run within contao).
	 * Also detect the contao version and local configuration settings.
	 *
	 * @param RootPackageInterface $package
	 *
	 * @return string
	 */
	static public function getContaoRoot(RootPackageInterface $package)
	{
		if (!defined('TL_ROOT')) {
			$root = dirname(getcwd());

			$extra = $package->getExtra();
			$cwd = getcwd();

			if (!empty($extra['contao']['root'])) {
				$root = $cwd . DIRECTORY_SEPARATOR . $extra['contao']['root'];
			}
			// test, do we have the core within vendor/contao/core.
			else {
				$vendorRoot = $cwd . DIRECTORY_SEPARATOR .
					'vendor' . DIRECTORY_SEPARATOR .
					'contao' . DIRECTORY_SEPARATOR .
					'core';

				if (is_dir($vendorRoot)) {
					$root = $vendorRoot;
				}
			}

			define('TL_ROOT', $root);
		}
		else {
			$root = TL_ROOT;
		}

		$systemDir = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR;
		$configDir = $systemDir . 'config' . DIRECTORY_SEPARATOR;

		if (!defined('VERSION')) {
			// Contao 3+
			if (file_exists(
				$constantsFile = $configDir . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			// Contao 2+
			else if (file_exists(
				$constantsFile = $systemDir . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			else {
				throw new \RuntimeException('Could not find constants.php in ' . $root);
			}
		}

		if (empty($GLOBALS['TL_CONFIG'])) {
			if (version_compare(VERSION, '3', '>=')) {
				// load default.php
				require_once($configDir . 'default.php');
			}
			else {
				// load config.php
				require_once($configDir . 'config.php');
			}

			// load localconfig.php
			$file = $configDir . 'localconfig.php';
			if (file_exists($file)) {
				require_once($file);
			}
		}

		return $root;
	}
}