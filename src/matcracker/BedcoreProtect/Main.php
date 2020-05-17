<?php

/*
 *     ___         __                 ___           __          __
 *    / _ )___ ___/ /______  _______ / _ \_______  / /____ ____/ /_
 *   / _  / -_) _  / __/ _ \/ __/ -_) ___/ __/ _ \/ __/ -_) __/ __/
 *  /____/\__/\_,_/\__/\___/_/  \__/_/  /_/  \___/\__/\__/\__/\__/
 *
 * Copyright (C) 2019
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author matcracker
 * @link https://www.github.com/matcracker/BedcoreProtect
 *
*/

declare(strict_types=1);

namespace matcracker\BedcoreProtect;

use JackMD\UpdateNotifier\UpdateNotifier;
use matcracker\BedcoreProtect\commands\BCPCommand;
use matcracker\BedcoreProtect\commands\CommandParser;
use matcracker\BedcoreProtect\listeners\BedcoreListener;
use matcracker\BedcoreProtect\listeners\BlockListener;
use matcracker\BedcoreProtect\listeners\BlockSniperListener;
use matcracker\BedcoreProtect\listeners\EntityListener;
use matcracker\BedcoreProtect\listeners\InspectorListener;
use matcracker\BedcoreProtect\listeners\PlayerListener;
use matcracker\BedcoreProtect\listeners\WorldListener;
use matcracker\BedcoreProtect\storage\Database;
use matcracker\BedcoreProtect\tasks\SQLiteTransactionTask;
use matcracker\BedcoreProtect\utils\ConfigParser;
use pocketmine\lang\BaseLang;
use pocketmine\plugin\PluginBase;
use function mkdir;
use function version_compare;

final class Main extends PluginBase
{
    public const PLUGIN_NAME = "BedcoreProtect";
    public const PLUGIN_TAG = "[" . self::PLUGIN_NAME . "]";
    public const MESSAGE_PREFIX = "&3" . self::PLUGIN_NAME . " &f- ";

    /** @var Main */
    private static $instance;
    /** @var BaseLang */
    private $baseLang;
    /** @var Database */
    private $database;
    /** @var ConfigParser */
    private $configParser;
    /** @var ConfigParser */
    private $oldConfigParser;
    /** @var bool */
    private $bsHooked = false;
    /** @var BedcoreListener[] */
    private $events;

    public static function getInstance(): Main
    {
        return self::$instance;
    }

    public function getLanguage(): BaseLang
    {
        return $this->baseLang;
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getParsedConfig(): ConfigParser
    {
        return $this->configParser;
    }

    /**
     * It restores an old copy of @see ConfigParser before plugin reload.
     */
    public function restoreParsedConfig(): void
    {
        $this->configParser = $this->oldConfigParser;
    }

    /**
     * Reloads the plugin configuration and returns true if config is valid.
     * @return bool
     */
    public function reloadPlugin(): bool
    {
        $this->oldConfigParser = $this->configParser;
        $this->reloadConfig();
        $this->configParser = (new ConfigParser($this->getConfig()))->validate();

        if ($this->configParser->isValidConfig()) {
            foreach ($this->events as $event) {
                $event->config = $this->configParser;
            }
            $this->baseLang = new BaseLang($this->configParser->getLanguage(), $this->getFile() . 'resources/languages/');
            return true;
        }

        return false;
    }

    public function onLoad(): void
    {
        self::$instance = $this;

        @mkdir($this->getDataFolder());

        $this->configParser = (new ConfigParser($this->getConfig()))->validate();
        if (!$this->configParser->isValidConfig()) {
            $this->getServer()->getPluginManager()->disablePlugin($this);
            echo "build k thx";
            return;
        }

        $this->saveResource($this->configParser->getDatabaseFileName());

        $this->baseLang = new BaseLang($this->configParser->getLanguage(), $this->getFile() . 'resources/languages/');

        if ($this->configParser->getBlockSniperHook()) {
            $bsPlugin = $this->getServer()->getPluginManager()->getPlugin('BlockSniper');
            $this->bsHooked = $bsPlugin !== null;
            if (!$this->bsHooked) {
                $this->getLogger()->warning($this->baseLang->translateString('blocksniper.hook.no-hook'));
            }
        }

        if ($this->configParser->getCheckUpdates()) {
            UpdateNotifier::checkUpdate($this->getName(), $this->getDescription()->getVersion());
        }
    }

    public function onEnable(): void
    {
        $this->database = new Database($this);

        $pluginManager = $this->getServer()->getPluginManager();
        //Database connection
        if (!$this->database->connect()) {
            $pluginManager->disablePlugin($this);
            return;
        }

        $queryManager = $this->database->getQueryManager();

        $version = $this->getVersion();
        $queryManager->init($version);
        $dbVersion = $this->database->getVersion();
        if (version_compare($version, $dbVersion) < 0) {
            $this->getLogger()->warning($this->baseLang->translateString('database.version.higher'));
            $pluginManager->disablePlugin($this);
            return;
        }

        if ($this->database->getPatchManager()->patch()) {
            $this->getLogger()->info($this->baseLang->translateString('database.version.updated', [$dbVersion, $version]));
        }

        $queryManager->setupDefaultData();

        if ($this->configParser->isSQLite()) {
            $queryManager->getPluginQueries()->beginTransaction();
            $this->getScheduler()->scheduleDelayedRepeatingTask(
                new SQLiteTransactionTask($queryManager->getPluginQueries()),
                SQLiteTransactionTask::getTicks(),
                SQLiteTransactionTask::getTicks()
            );
        }

        CommandParser::initActions();

        $this->getServer()->getCommandMap()->register('bedcoreprotect', new BCPCommand($this));

        //Registering events
        $this->events = [
            new BlockListener($this),
            new EntityListener($this),
            new PlayerListener($this),
            new WorldListener($this),
            new InspectorListener($this),
            new BlockSniperListener($this)
        ];

        foreach ($this->events as $event) {
            $pluginManager->registerEvents($event, $this);
        }
    }

    /**
     * Returns the plugin version.
     * @return string
     */
    public function getVersion(): string
    {
        return $this->getDescription()->getVersion();
    }

    public function isBlockSniperHooked(): bool
    {
        return $this->bsHooked;
    }

    public function onDisable(): void
    {
        $this->getScheduler()->cancelAllTasks();
        $this->database->disconnect();

        Inspector::removeAll();
        $this->bsHooked = false;
        unset($this->database, $this->baseLang, $this->configParser, $this->oldConfigParser, $this->events);
    }
}
