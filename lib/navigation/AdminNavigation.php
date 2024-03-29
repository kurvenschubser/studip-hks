<?php
# Lifter010: TODO
/*
 * AdminNavigation.php - navigation for admin area
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * @author      Elmar Ludwig
 * @author      Michael Riehemann <michael.riehemann@uni-oldenburg.de>
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GPL version 2
 * @category    Stud.IP
 */

class AdminNavigation extends Navigation
{
    /**
     * Initialize a new Navigation instance.
     */
    public function __construct()
    {
        parent::__construct(_('Admin'));

        $this->setImage('header/admin.png', array('title' => _('Zu Ihrer Administrationsseite'), "@2x" => TRUE));
    }

    /**
     * Initialize the subnavigation of this item. This method
     * is called once before the first item is added or removed.
     */
    public function initSubNavigation()
    {
        global $SessionSeminar, $SessSemName, $archive_kill, $perm;

        parent::initSubNavigation();

        if ($SessSemName['class'] == 'inst') {
            if ($_SESSION['links_admin_data']['referred_from'] == 'inst') {
                $back_jump= _('zurück zur ausgewählten Einrichtung');
            } else {
                $back_jump= _('zur ausgewählten Einrichtung');
            }
        } else if ($SessSemName['class'] == 'sem') {
            if ($_SESSION['links_admin_data']['referred_from'] == 'sem' && !$archive_kill && !$_SESSION['links_admin_data']['assi']) {
                $back_jump= _('zurück zur ausgewählten Veranstaltung');
            } else if ($_SESSION['links_admin_data']['referred_from'] == 'assi' && !$archive_kill) {
                $back_jump= _('zur neu angelegten Veranstaltung');
            } else if (!$_SESSION['links_admin_data']['assi']) {
                $back_jump= _('zur ausgewählten Veranstaltung');
            }
        }

        $sem_create_perm = in_array(get_config('SEM_CREATE_PERM'), array('root','admin','dozent')) ? get_config('SEM_CREATE_PERM') : 'dozent';

        // course administration
        $navigation = new Navigation(_('Veranstaltungen'), 'adminarea_start.php?list=TRUE');

        if ($SessionSeminar == null) {
            $navigation->addSubNavigation('adminarea_start', new Navigation(_('Veranstaltung auswählen'),
                                          'adminarea_start.php', array('list' => 'TRUE')));
        }

        $navigation->addSubNavigation('details', new Navigation(_('Grunddaten'),
                                      'dispatch.php/course/basicdata/view/', array('list' => 'TRUE')));
        $navigation->addSubNavigation('study_areas', new Navigation(_('Studienbereiche'),
                                      'dispatch.php/course/study_areas/show/', array('list' => 'TRUE')));
        $navigation->addSubNavigation('dates', new Navigation(_('Zeiten / Räume'), 'raumzeit.php?list=TRUE'));
        if (get_config('RESOURCES_ENABLE') && get_config('RESOURCES_ALLOW_ROOM_REQUESTS')) {
            $navigation->addSubNavigation('room_requests', new Navigation(_('Raumanfragen'), 'dispatch.php/course/room_requests', array('list' => 'TRUE')));
        }
        $navigation->addSubNavigation('schedule', new Navigation(_('Ablaufplan'), 'dispatch.php/course/dates'));

        if (get_config('VOTE_ENABLE')) {
            $navigation->addSubNavigation('vote', new Navigation(_('Umfragen und Tests'), 'admin_vote.php?view=vote_sem&list=TRUE'));
            $navigation->addSubNavigation('evaluation', new Navigation(_('Evaluationen'), 'admin_evaluation.php?view=eval_sem&list=TRUE'));
        }

        if (get_config('LITERATURE_ENABLE')) {
            $navigation->addSubNavigation('literature', new Navigation(_('Literatur'), 'admin_lit_list.php?list=TRUE&view=literatur_sem'));
        }

        $navigation->addSubNavigation('admission', new Navigation(_('Zugangsberechtigungen'), 'dispatch.php/course/admission', array('list' => 'TRUE')));
        $navigation->addSubNavigation('groups', new Navigation(_('Funktionen / Gruppen'), 'admin_statusgruppe.php?list=TRUE'));
        $navigation->addSubNavigation('modules', new Navigation(_('Inhaltselemente'), 'dispatch.php/course/plus/index?list=TRUE'));

        if ($perm->have_perm($sem_create_perm)) {
            $navigation->addSubNavigation('copy', new Navigation(_('Veranstaltung kopieren'), 'copy_assi.php?list=TRUE&new_session=TRUE'));
            $navigation->addSubNavigation('create', new Navigation(_('Neue Veranstaltung anlegen'), 'admin_seminare_assi.php?new_session=TRUE'));
            $navigation->addSubNavigation('archive', new Navigation(_('Archivieren'), 'archiv_assi.php?list=TRUE&new_session=TRUE'));
            $navigation->addSubNavigation('visibility', new Navigation(_('Sichtbarkeit'), 'admin_visibility.php?list=TRUE'));
        }

        $navigation->addSubNavigation('lock_rules', new Navigation(_('Sperren'), 'admin_lock.php?list=TRUE'));

        $navigation->addSubNavigation('aux_data', new Navigation(_('Zusatzangaben'), 'admin_aux.php?list=TRUE'));
        $this->addSubNavigation('course', $navigation);

        // institute administration
        $navigation = new Navigation(_('Einrichtungen'));

        $navigation->setURL('admin_institut.php?list=TRUE&quit=1');
        $navigation->addSubNavigation('details', new Navigation(_('Grunddaten'), 'admin_institut.php?list=TRUE'));
        $navigation->addSubNavigation('faculty', new Navigation(_('Mitarbeiter'), 'inst_admin.php?list=TRUE&admin_view=1'));
        $navigation->addSubNavigation('groups', new Navigation(_('Funktionen / Gruppen'), 'dispatch.php/admin/statusgroups?type=inst&list=TRUE'));

        if (get_config('VOTE_ENABLE')) {
            $navigation->addSubNavigation('vote', new Navigation(_('Umfragen und Tests'), 'admin_vote.php?view=vote_inst&list=TRUE'));
            $navigation->addSubNavigation('evaluation', new Navigation(_('Evaluationen'), 'admin_evaluation.php?view=eval_inst'));
        }

        if (get_config('LITERATURE_ENABLE')) {
            $navigation->addSubNavigation('literature', new Navigation(_('Literatur'), 'admin_lit_list.php?list=TRUE&view=literatur_inst'));
        }

        $navigation->addSubNavigation('modules', new Navigation(_('Inhaltselemente'), 'dispatch.php/course/plus/index?list=TRUE'));

        if (get_config('EXTERN_ENABLE')) {
            $navigation->addSubNavigation('external', new Navigation(_('Externe Seiten'), 'admin_extern.php?list=TRUE&view=extern_inst'));
        }

        if ($perm->have_perm("root") || ($perm->is_fak_admin() && get_config('INST_FAK_ADMIN_PERMS') != 'none')) {
            $navigation->addSubNavigation('create', new Navigation(_('Neue Einrichtung anlegen'), 'admin_institut.php?i_view=new'));
        }

        $this->addSubNavigation('institute', $navigation);

        // global config / user administration
        $navigation = new Navigation(_('Globale Einstellungen'));

        if (!get_config('RESTRICTED_USER_MANAGEMENT') || $perm->have_perm('root')) {
            $navigation->addSubNavigation('user', new Navigation(_('Benutzer'), 'dispatch.php/admin/user/'));
        }

        if ($perm->have_perm(get_config('RANGE_TREE_ADMIN_PERM') ? get_config('RANGE_TREE_ADMIN_PERM') : 'admin')) {
            $navigation->addSubNavigation('range_tree', new Navigation(_('Einrichtungshierarchie'), 'admin_range_tree.php'));
        }

        if ($perm->have_perm(get_config('SEM_TREE_ADMIN_PERM') ? get_config('SEM_TREE_ADMIN_PERM') : 'admin') && $perm->is_fak_admin()) {
            $navigation->addSubNavigation('sem_tree', new Navigation(_('Veranstaltungshierarchie'), 'admin_sem_tree.php'));
        }

        if ($perm->have_perm(get_config('AUX_RULE_ADMIN_PERM') ? get_config('AUX_RULE_ADMIN_PERM') : 'admin')) {
            $navigation->addSubNavigation('specification', new Navigation(_('Zusatzangaben'), 'dispatch.php/admin/specification'));
        }

        if ($perm->have_perm(get_config('LOCK_RULE_ADMIN_PERM') ? get_config('LOCK_RULE_ADMIN_PERM') : 'admin')) {
            $navigation->addSubNavigation('lock_rules', new Navigation(_('Sperrebenen'), 'dispatch.php/admin/lockrules'));
        }

        if ($perm->have_perm('root')) {
            $navigation->addSubNavigation('plugins', new Navigation(_('Plugins'), 'dispatch.php/admin/plugin'));
            $navigation->addSubNavigation('roles', new Navigation(_('Rollen'), 'dispatch.php/admin/role'));
            $navigation->addSubNavigation('user_domains', new Navigation(_('Nutzerdomänen'), 'dispatch.php/admin/domain'));
            $navigation->addSubNavigation('datafields', new Navigation(_('Datenfelder'), 'dispatch.php/admin/datafields'));
            $navigation->addSubNavigation('configuration', new Navigation(_('Konfiguration'), 'dispatch.php/admin/configuration/configuration'));
            $navigation->addSubNavigation('auto_insert', new Navigation(_('Automatisiertes Eintragen'), 'dispatch.php/admin/autoinsert'));

            if (get_config('BANNER_ADS_ENABLE'))  {
                $navigation->addSubNavigation('banner', new Navigation(_('Werbebanner'), 'dispatch.php/admin/banner'));
            }

            if (get_config('SEMESTER_ADMINISTRATION_ENABLE')) {
                $navigation->addSubNavigation('semester', new Navigation(_('Semester'), 'dispatch.php/admin/semester'));
            }

            if (get_config('EXTERN_ENABLE')) {
                $navigation->addSubNavigation('external', new Navigation(_('Externe Seiten'), 'admin_extern.php?list=TRUE&view=extern_global'));
            }

            $navigation->addSubNavigation('studygroup', new Navigation(_('Studiengruppen'), 'dispatch.php/course/studygroup/globalmodules'));

            $navigation->addSubNavigation('studycourse', new Navigation(_('Studiengänge'), 'dispatch.php/admin/studycourse/profession'));

            if (get_config('SMILEYADMIN_ENABLE')) {
                $navigation->addSubNavigation('smileys', new Navigation(_('Smileys'), 'dispatch.php/admin/smileys'));
            }

            if (get_config('TOURS_ENABLE')) {
                $navigation->addSubNavigation('tour', new Navigation(_('Touren'), 'dispatch.php/tour/admin_overview'));
            }

            if (get_config('ELEARNING_INTERFACE_ENABLE')) {
                $navigation->addSubNavigation('elearning', new Navigation(_('Lernmodul-Schnittstelle'), 'admin_elearning_interface.php'));
            }

            if (get_config('WEBSERVICES_ENABLE')) {
                $navigation->addSubNavigation('webservice_access', new Navigation(_('Webservice'), 'dispatch.php/admin/webservice_access'));
            }
            $navigation->addSubNavigation('sem_classes', new Navigation(_('Veranstaltungskategorien'), 'dispatch.php/admin/sem_classes/overview'));

            if (Config::get()->CRONJOBS_ENABLE) {
                $navigation->addSubNavigation('cronjobs', new Navigation(_('Cronjobs'), 'dispatch.php/admin/cronjobs/schedules'));
            }

            $navigation->addSubNavigation('admissionrules', new Navigation(_('Anmelderegeln'), 'dispatch.php/admission/ruleadministration'));

            $navigation->addSubNavigation('api', new Navigation(_('API'), 'dispatch.php/admin/api'));
        }

        $this->addSubNavigation('config', $navigation);

        // log view
        if ($perm->have_perm('root') && get_config('LOG_ENABLE')) {
            $navigation = new Navigation(_('Log'));
            $navigation->addSubNavigation('show', new Navigation(_('Log'), 'dispatch.php/event_log/show'));
            $navigation->addSubNavigation('admin', new Navigation(_('Einstellungen'), 'dispatch.php/event_log/admin'));
            $this->addSubNavigation('log', $navigation);
        }

        // link to course
        if ($SessSemName['class'] == 'inst') {
            $navigation = new Navigation($back_jump, 'dispatch.php/institute/overview?auswahl='.$SessSemName[1]);
            $this->addSubNavigation('back_jump', $navigation);
        } else if ($SessSemName['class'] == 'sem' && !$archive_kill && !$_SESSION['links_admin_data']['assi']) {
            $navigation = new Navigation($back_jump, 'seminar_main.php?auswahl='.$SessSemName[1]);
            $this->addSubNavigation('back_jump', $navigation);
        }

        // admin plugins
        $navigation = new Navigation(_('Admin-Plugins'));
        $this->addSubNavigation('plugins', $navigation);
    }
}
