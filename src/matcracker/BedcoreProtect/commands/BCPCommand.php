<?php

/*
 * BedcoreProtect
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

namespace matcracker\BedcoreProtect\commands;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use matcracker\BedcoreProtect\Inspector;
use matcracker\BedcoreProtect\Main;
use matcracker\BedcoreProtect\utils\Utils;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use poggit\libasynql\SqlError;

final class BCPCommand extends Command
{
    private const CMD_PREFIX = "&3" . Main::PLUGIN_NAME . "&f- ";

    private $plugin;
    private $queries;

    public function __construct(Main $plugin)
    {
        parent::__construct(
            "bedcoreprotect",
            Main::PLUGIN_NAME . " command",
            null,
            ["core", "co", "bcp"]
        );
        $this->plugin = $plugin;
        $this->queries = $plugin->getDatabase()->getQueries();
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (empty($args)) {
            return false;
        }

        $subCmd = strtolower($args[0]);
        if (!$sender->hasPermission("bcp.command.bedcoreprotect") || !$sender->hasPermission("bcp.subcommand.{$subCmd}")) {
            $sender->sendMessage(self::CMD_PREFIX . " - &cYou don't have permission to run this command.");
            return true;
        }

        if ($sender instanceof Player) {
            switch ($subCmd) {
                case "inspect":
                case "i":
                    $b = Inspector::isInspector($sender);
                    $b ? Inspector::removeInspector($sender) : Inspector::addInspector($sender);
                    $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . ($b ? "Disabled" : "Enabled") . " inspector mode."));
                    return true;
                case "lookup":
                case "l":
                    if (count($logs = Inspector::getCachedLogs($sender)) > 0) {
                        $page = 0;
                        if (isset($args[1])) {
                            if (!ctype_digit($args[1])) {
                                $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cThe page value must be numeric!"));
                                return true;
                            }
                            $page = (int)$args[1];
                        }
                        Inspector::parseLogs($sender, $logs, $page);
                    }
                    return true;
                case "near":
                    $near = $this->plugin->getParsedConfig()->getDefaultRadius();
                    if (isset($args[1])) {
                        if (!ctype_digit($args[1])) {
                            $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cThe near value must be numeric!"));
                            return true;
                        }
                        $near = (int)$args[1];
                        if ($near < 1 || $near > 15) {
                            $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cThe near value must be between 1 and 15!"));
                            return true;
                        }
                    }
                    $this->queries->requestNearLog($sender, $sender, $near);
                    return true;
                case "rollback":
                case "rb":
                    if (isset($args[1])) {
                        $parser = new CommandParser($this->plugin->getParsedConfig(), $args, true);
                        if ($parser->parse()) {
                            $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Starting rollback on \"" . $sender->getLevel()->getFolderName() . "\"."));
                            $sender->sendMessage(Utils::translateColors("&f------"));
                            $start = microtime(true);

                            $this->queries->rollback($sender->asPosition(), $parser,
                                function (int $countRows, CommandParser $parser) use ($sender, $start) { //onSuccess
                                    if ($countRows > 0) {
                                        $diff = microtime(true) - $start;
                                        $time = $parser->getTime();
                                        $radius = $parser->getRadius();
                                        $date = Carbon::createFromTimestamp(time() - (int)$time)->diffForHumans(null, null, true, 2, CarbonInterface::JUST_NOW);
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Rollback completed for \"" . $sender->getLevel()->getFolderName() . "\"."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Rolled back $date."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Radius: $radius block(s)."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Approx. $countRows block(s) changed."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Time taken: " . round($diff, 1) . " second(s)."));
                                        $sender->sendMessage(Utils::translateColors("&f------"));
                                    } else {
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cNo data to rollback."));
                                    }
                                },
                                function (SqlError $error) use ($sender) { //onError
                                    $this->plugin->getLogger()->alert($error->getErrorMessage());
                                    $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cAn error occurred while restoring. Check the console."));
                                    $sender->sendMessage(Utils::translateColors("&f------"));
                                }
                            );
                        } else {
                            if ($parser->getTime() === null) {
                                $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cPlease specify the amount of time to rollback."));
                            } else {
                                $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cYou insert wrong parameters.")); //TODO: Check original message
                            }
                        }
                        return true;
                    } else {
                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cYou must add at least one parameter.")); //TODO: Check original message
                    }
                    return true;
                case "restore":
                    if (isset($args[1])) {
                        $parser = new CommandParser($this->plugin->getParsedConfig(), $args, true);
                        if ($parser->parse()) {
                            $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Restore started on \"" . $sender->getLevel()->getFolderName() . "\"."));
                            $sender->sendMessage(Utils::translateColors("&f------"));
                            $start = microtime(true);

                            $this->queries->restore($sender->asPosition(), $parser,
                                function (int $countRows, CommandParser $parser) use ($sender, $start) { //onSuccess
                                    if ($countRows > 0) {
                                        $diff = microtime(true) - $start;
                                        $time = $parser->getTime();
                                        $radius = $parser->getRadius();
                                        $date = Carbon::createFromTimestamp(time() - (int)$time)->diffForHumans(null, null, true, 2, CarbonInterface::JUST_NOW);
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Restore completed for \"" . $sender->getLevel()->getFolderName() . "\"."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Restored $date."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Radius: $radius block(s)."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Approx. $countRows block(s) changed."));
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "Time taken: " . round($diff, 1) . " second(s)."));
                                        $sender->sendMessage(Utils::translateColors("&f------"));
                                    } else {
                                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cNo data to restore."));
                                    }
                                },
                                function (SqlError $error) use ($sender) { //onError
                                    $this->plugin->getLogger()->alert($error->getErrorMessage());
                                    $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cAn error occurred while restoring. Check the console."));
                                    $sender->sendMessage(Utils::translateColors("&f------"));
                                }
                            );
                        } else {
                            if ($parser->getTime() === null) {
                                $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cPlease specify the amount of time to restore."));
                            } else {
                                $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cYou insert wrong parameters.")); //TODO: Check original message
                            }
                        }
                        return true;
                    } else {
                        $sender->sendMessage(Utils::translateColors(self::CMD_PREFIX . "&cYou must add at least one parameter.")); //TODO: Check original message
                    }
                    return true;
                case "help": //TODO: help subcmd
                    $sender->sendMessage(self::CMD_PREFIX . "&bShowing help page");
                    $sender->sendMessage("/bcp help: Shows help page.");
                    $sender->sendMessage(Utils::translateColors("&f------"));
                    return true;
                default:
                    return false;
            }
        }

        return false;
    }


}