<?php
# Lifter002: TODO
# Lifter007: TODO
# Lifter003: TODO
# Lifter010: TODO

/*
 * contentmodule_webservice.php - Provides webservices for infos about
 *  contentmodules
 *
 * Copyright (C) 2006 - Marco Diedrich (mdiedric@uos.de)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 */

require_once('lib/webservices/api/studip_contentmodule.php');

class ContentmoduleService extends AccessControlledService
{
    function ContentmoduleService()
    {
    $this->add_api_method('find_seminars_using_contentmodule',
                          array('string', 'string', 'string'),
                          array('string'),
                          '');

    $this->add_api_method('find_institutes_using_contentmodule',
                          array('string', 'string', 'string'),
                          array('string'),
                          '');

    }

    function find_seminars_using_contentmodule_action($api_key, $system_type, $module_id)
    {
        return StudipContentmoduleHelper::find_seminars_using_contentmodule($system_type, $module_id);
    }

    function find_institutes_using_contentmodule_action($api_key, $system_type, $module_id)
    {
        return StudipContentmoduleHelper::find_institutes_using_contentmodule($system_type, $module_id);
    }
}
