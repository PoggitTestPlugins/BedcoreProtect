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

namespace matcracker\BedcoreProtect\ui;

use Closure;
use matcracker\BedcoreProtect\commands\CommandParser;
use matcracker\BedcoreProtect\Main;
use matcracker\BedcoreProtect\utils\ConfigParser;
use matcracker\BedcoreProtect\utils\Utils;
use matcracker\FormLib\BaseForm;
use matcracker\FormLib\CustomForm;
use matcracker\FormLib\Form;
use pocketmine\Player;
use function array_keys;
use function is_array;
use function strlen;

final class Forms
{
    /** @var ConfigParser */
    private $configParser;

    public function __construct(ConfigParser $configParser)
    {
        $this->configParser = $configParser;
    }

    public function getMainMenu(): BaseForm
    {
        $lang = Main::getInstance()->getLanguage();
        return (new Form(
            function (Player $player, $data) {
                switch ((int)$data) { //Clicked button
                    case 0: //Inspector
                        $player->chat('/bcp inspect');
                        break;
                    case 1: //Near
                        $player->sendForm($this->getNearMenu());
                        break;
                    case 2: //Lookup
                        $player->sendForm($this->getInputMenu('lookup'));
                        break;
                    case 3: //Rollback
                        $player->sendForm($this->getInputMenu('rollback'));
                        break;
                    case 4: //Restore
                        $player->sendForm($this->getInputMenu('restore'));
                        break;
                    case 5: //Purge
                        $player->sendForm($this->getPurgeMenu());
                        break;
                    case 6: //Reload
                        $player->chat('/bcp reload');
                        break;
                    case 7: //Status
                        $player->chat('/bcp status');
                        break;
                }
            }
        ))->setMessage($lang->translateString('form.menu.option'))
            ->addClassicButton($lang->translateString('form.menu.inspector'))
            ->addClassicButton($lang->translateString('form.menu.near'))
            ->addClassicButton($lang->translateString('form.menu.lookup'))
            ->addClassicButton($lang->translateString('general.rollback'))
            ->addClassicButton($lang->translateString('general.restore'))
            ->addClassicButton($lang->translateString('form.menu.purge'))
            ->addClassicButton($lang->translateString('form.menu.reload'))
            ->addClassicButton($lang->translateString('form.menu.status'))
            ->setTitle(Utils::translateColors('&3&l' . Main::PLUGIN_NAME . " " . $lang->translateString('form.menu.title')));
    }

    private function getPurgeMenu(): BaseForm
    {
        $lang = Main::getInstance()->getLanguage();
        return (new CustomForm(
            function (Player $player, $data) {
                if (is_array($data)) {
                    $player->chat("/bcp purge t={$data[0]}");
                }
            },
            function (Player $player) {
                $player->sendForm($this->getMainMenu());
            }
        ))->addInput($lang->translateString('form.purge-menu.time'), '1h3m10s')
            ->setTitle(Utils::translateColors('&3&l' . $lang->translateString('form.menu.purge')));
    }

    private function getNearMenu(): BaseForm
    {
        $lang = Main::getInstance()->getLanguage();
        return (new CustomForm(
            function (Player $player, $data) {
                if (is_array($data)) {
                    $player->chat("/bcp near {$data[0]}");
                }
            },
            function (Player $player) {
                $player->sendForm($this->getMainMenu());
            }
        ))->addSlider($lang->translateString('form.input-menu.radius'), 1, $this->configParser->getMaxRadius(), null, $this->configParser->getDefaultRadius())
            ->setTitle(Utils::translateColors('&3&l' . $lang->translateString('form.menu.near')));
    }

    private function getInputMenu(string $type): BaseForm
    {
        $lang = Main::getInstance()->getLanguage();
        return (new CustomForm(
            $this->parseForm($type),
            function (Player $player) {
                $player->sendForm($this->getMainMenu());
            }
        ))->addLabel($lang->translateString('form.input-menu.required-fields'))
            ->addInput($lang->translateString('form.input-menu.time'), "1h3m10s")
            ->addSlider($lang->translateString('form.input-menu.radius'), 0, $this->configParser->getMaxRadius())
            ->addLabel($lang->translateString('form.input-menu.optional-fields'))
            ->addInput($lang->translateString('form.input-menu.user-entity'), $lang->translateString('form.input-menu.user-entity-placeholder'))
            ->addDropdown($lang->translateString('general.action'), array_keys(CommandParser::$ACTIONS), -1)
            ->addInput($lang->translateString('form.input-menu.restrict-blocks'), 'stone,dirt,2:0')
            ->addInput($lang->translateString('form.input-menu.exclude-blocks'), 'stone,dirt,2:0')
            ->setTitle(Utils::translateColors('&3&l' . $lang->translateString("general.{$type}")));
    }

    private function parseForm(string $subCmd): Closure
    {
        return function (Player $player, $data) use ($subCmd) {
            if (is_array($data)) {
                $time = "t={$data[1]}";
                $radius = $data[2] === 0 ? '' : "r={$data[2]}";
                $user = strlen($data[4]) === 0 ? '' : "u={$data[4]}";
                $action = '';
                if ($data[5] !== -1) {
                    $a = array_keys(CommandParser::$ACTIONS)[$data[5]];
                    $action = "a={$a}";
                }
                $includeBlocks = strlen($data[6]) === 0 ? '' : "b={$data[6]}";
                $excludeBlocks = strlen($data[7]) === 0 ? '' : "e={$data[7]}";
                $player->chat("/bcp {$subCmd} {$time} {$radius} {$user} {$action} {$includeBlocks} {$excludeBlocks}");
            }
        };
    }
}
