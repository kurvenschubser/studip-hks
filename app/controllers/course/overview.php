<?php
# Lifter010: TODO

/*
 * Copyright (C) 2014 - Rasmus Fuhse <fuhse@data-quest.de>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 */

require_once 'app/controllers/authenticated_controller.php';
require_once 'lib/classes/Seminar.class.php';
include 'lib/showNews.inc.php';
include 'lib/show_dates.inc.php';
if (get_config('VOTE_ENABLE')) {
    include_once ("lib/vote/vote_show.inc.php");
}

class Course_OverviewController extends AuthenticatedController
{
    protected $allow_nobody = true;

    function before_filter(&$action, &$args) {
        global $SEM_TYPE, $SEM_CLASS;

        parent::before_filter($action, $args);

        checkObject();
        $this->course = Course::findCurrent();
        if (!$this->course) {
            throw new CheckObjectException(_('Sie haben kein Objekt gew�hlt.'));
        }
        $this->course_id = $this->course->id;

        PageLayout::setHelpKeyword("Basis.InVeranstaltungKurzinfo");
        PageLayout::setTitle($GLOBALS['SessSemName']["header_line"]. " - " . _("Kurzinfo"));
        Navigation::activateItem('/course/main/info');
        // add skip link
        SkipLinks::addIndex(Navigation::getItem('/course/main/info')->getTitle(), 'main_content', 100);


        $this->sem = Seminar::getInstance($this->course_id);
        $sem_class = $GLOBALS['SEM_CLASS'][$GLOBALS['SEM_TYPE'][$this->sem->status]['class']];
        $sem_class || $sem_class = SemClass::getDefaultSemClass();
        $this->studygroup_mode = $SEM_CLASS[$SEM_TYPE[$this->sem->status]["class"]]["studygroup_mode"];

    }

    /**
     * This method is called to show the form to upload a new avatar for a
     * course.
     *
     * @return void
     */
    function index_action()
    {
        // nothing to do
        if ($this->studygroup_mode) {
            $this->avatar = StudygroupAvatar::getAvatar($this->course_id);
        } else {
            $this->avatar = CourseAvatar::getAvatar($this->course_id);
        }

        if (get_config('NEWS_RSS_EXPORT_ENABLE') && $this->course_id){
            $rss_id = StudipNews::GetRssIdFromRangeId($this->course_id);
            if ($rss_id) {
                PageLayout::addHeadElement('link', array('rel'   => 'alternate',
                                                         'type'  => 'application/rss+xml',
                                                         'title' => 'RSS',
                                                         'href'  => 'rss.php?id='.$rss_id));
            }
        }


    }
}
