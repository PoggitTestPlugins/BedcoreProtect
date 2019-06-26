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

namespace matcracker\BedcoreProtect\commands;

use matcracker\BedcoreProtect\Inspector;
use matcracker\BedcoreProtect\Main;
use matcracker\BedcoreProtect\utils\Utils;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use poggit\libasynql\SqlError;

final class BCPCommand extends Command{

	private $plugin;
	private $queries;

	public function __construct(Main $plugin){
		parent::__construct(
			"bedcoreprotect",
			"It runs the BedcoreProtect commands.",
			"Usage: /bcp help",
			["core", "co", "bcp"]
		);
		$this->plugin = $plugin;
		$this->queries = $plugin->getDatabase()->getQueries();
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(empty($args)){
			return false;
		}

		$subCmd = strtolower($args[0]);
		if(!$sender->hasPermission("bcp.command.bedcoreprotect") || !$sender->hasPermission("bcp.subcommand.{$subCmd}")){
			$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cYou don't have permission to run this command."));

			return true;
		}

		//Shared commands between player and console.
		switch($subCmd){
			case "help":
				isset($args[1]) ? BCPHelpCommand::showSpecificHelp($sender, $args[1]) : BCPHelpCommand::showGenericHelp($sender);

				return true;
			case "lookup":
			case "l":
				if(isset($args[1])){
					$parser = new CommandParser($this->plugin->getParsedConfig(), $args, ["time"], true);
					if($parser->parse()){
						$this->queries->requestLookup($sender, $parser);
					}else{
						if(count($logs = Inspector::getCachedLogs($sender)) > 0){
							$page = 0;
							$lines = 4;
							if(isset($args[1])){
								$split = explode(":", $args[1]);
								if($ctype = ctype_digit($split[0])){
									$page = (int) $split[0];
								}

								if(isset($split[1]) && $ctype = ctype_digit($split[1])){
									$lines = (int) $split[1];
								}

								if(!$ctype){
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cWrong page/line value inserted!"));

									return true;
								}
							}
							Inspector::parseLogs($sender, $logs, ($page - 1), $lines);
						}else{
							$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&c{$parser->getErrorMessage()}."));
						}
					}
				}else{
					$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cYou must add at least one parameter."));
				}

				return true;
			case "purge":
				if(isset($args[1])){
					$parser = new CommandParser($this->plugin->getParsedConfig(), $args, ["time"], true);
					if($parser->parse()){
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Data purge started. This may take some time."));
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Do not restart your server until completed."));
						$this->queries->purge($parser->getTime(), function(int $affectedRows) use ($sender){
							$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Data purge successful."));
							$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "{$affectedRows} rows of data deleted."));
						});

						return true;
					}else{
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&c{$parser->getErrorMessage()}."));
					}
				}else{
					$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cYou must add at least one parameter."));
				}

				return true;
		}

		if(!($sender instanceof Player)){
			$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cYou can't run this command from console."));

			return true;
		}

		//Only players commands.
		switch($subCmd){
			case "inspect":
			case "i":
				$b = Inspector::isInspector($sender);
				$b ? Inspector::removeInspector($sender) : Inspector::addInspector($sender);
				$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . ($b ? "Disabled" : "Enabled") . " inspector mode."));

				return true;
			case "near":
				$near = 5;

				if(isset($args[1])){
					if(!ctype_digit($args[1])){
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cThe near value must be numeric!"));

						return true;
					}
					$near = (int) $args[1];
					$maxRadius = $this->plugin->getParsedConfig()->getMaxRadius();
					if($near < 1 || $near > $maxRadius){
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cThe near value must be between 1 and {$maxRadius}!"));

						return true;
					}
				}

				$this->queries->requestNearLog($sender, $sender, $near);

				return true;
			case "rollback":
			case "rb":
				if(isset($args[1])){
					$parser = new CommandParser($this->plugin->getParsedConfig(), $args, ["time", "radius"], true);
					if($parser->parse()){
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Starting rollback on \"{$sender->getWorld()->getFolderName()}\"."));
						$sender->sendMessage(Utils::translateColors("&f------"));
						$start = microtime(true);

						$this->queries->rollback($sender->asPosition(), $parser,
							function(int $countRows) use ($sender, $start, $parser){ //onSuccess
								if($countRows > 0){
									$diff = microtime(true) - $start;
									$time = time() - $parser->getTime();
									$radius = $parser->getRadius();
									$date = Utils::timeAgo($time);
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Rollback completed for \"{$sender->getWorld()->getFolderName()}\"."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Rolled back {$date}."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Radius: {$radius} block(s)."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Approx. {$countRows} block(s) changed."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Time taken: " . round($diff, 1) . " second(s)."));
									$sender->sendMessage(Utils::translateColors("&f------"));
									$y = $sender->getWorld()->getHighestBlockAt((int) $sender->getX(), (int) $sender->getZ()) + 1;
									if((int) $sender->getY() < $y){
										$sender->teleport($sender->setComponents($sender->getX(), $y, $sender->getZ()), $sender->getYaw(), $sender->getPitch());
										$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Teleported to the top."));
									}
								}else{
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cNo data to rollback."));
								}
							},
							function(SqlError $error) use ($sender){ //onError
								$this->plugin->getLogger()->alert($error->getErrorMessage());
								$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cAn error occurred while restoring. Check the console."));
								$sender->sendMessage(Utils::translateColors("&f------"));
							}
						);
					}else{
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&c{$parser->getErrorMessage()}."));
					}
				}else{
					$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cYou must add at least one parameter."));
				}

				return true;
			case "restore":
			case "rs":
				if(isset($args[1])){
					$parser = new CommandParser($this->plugin->getParsedConfig(), $args, ["time", "radius"], true);
					if($parser->parse()){
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Restore started on \"{$sender->getWorld()->getFolderName()}\"."));
						$sender->sendMessage(Utils::translateColors("&f------"));
						$start = microtime(true);

						$this->queries->restore($sender->asPosition(), $parser,
							function(int $countRows) use ($sender, $start, $parser){ //onSuccess
								if($countRows > 0){
									$diff = microtime(true) - $start;
									$time = time() - $parser->getTime();
									$radius = $parser->getRadius();
									$date = Utils::timeAgo($time);
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Restore completed for \"{$sender->getWorld()->getFolderName()}\"."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Restored {$date}."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Radius: {$radius} block(s)."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Approx. {$countRows} block(s) changed."));
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "Time taken: " . round($diff, 1) . " second(s)."));
									$sender->sendMessage(Utils::translateColors("&f------"));
								}else{
									$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cNo data to restore."));
								}
							},
							function(SqlError $error) use ($sender){ //onError
								$this->plugin->getLogger()->alert($error->getErrorMessage());
								$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cAn error occurred while restoring. Check the console."));
								$sender->sendMessage(Utils::translateColors("&f------"));
							}
						);
					}else{
						$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&c{$parser->getErrorMessage()}."));
					}
				}else{
					$sender->sendMessage(Utils::translateColors(Main::MESSAGE_PREFIX . "&cYou must add at least one parameter."));
				}

				return true;
		}

		return false;
	}


}