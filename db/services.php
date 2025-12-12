<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External functions and service declaration for BS Service Suite
 *
 * Documentation: {@link https://moodledev.io/docs/apis/subsystems/external/description}
 *
 * @package    local_bservicesuite
 * @category   webservice
 * @copyright  2025 Brain Station 23 ltd <sales@brainstation-23.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tarekul Islam <tarekul.islam@brainstation-23.com>
 */

defined('MOODLE_INTERNAL') || die();
use local_bservicesuite\externallib;


$functions = [
    'local_bservicesuite_get_analytics' => [
        'classname'    => externallib::class,
        'methodname'   => 'get_analytics',
        'description'  => 'Get courses',
        'type'         => 'read',
        'capabilities' => 'local/bsservicessuite:view',
        'services'     => ['moodle_mobile_app'],
    ],
];
