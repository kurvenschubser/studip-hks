<?php

/*
 * MembersController
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * @author      David Siegfried <david.siegfried@uni-oldenburg.de>
 * @author      Sebastian Hobert <sebastian.hobert@uni-goettingen.de>
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GPL version 2
 * @category    Stud.IP
 * @since       2.5
 */

require_once 'app/controllers/authenticated_controller.php';
require_once 'app/models/members.php';
require_once 'lib/messaging.inc.php'; //Funktionen des Nachrichtensystems

require_once 'lib/admission.inc.php'; //Funktionen der Teilnehmerbegrenzung
require_once 'lib/functions.php'; //Funktionen der Teilnehmerbegrenzung
require_once 'lib/language.inc.php'; //Funktionen der Teilnehmerbegrenzung
require_once 'lib/export/export_studipdata_func.inc.php'; // Funktionne f�r den Export

class Course_MembersController extends AuthenticatedController
{

    function before_filter(&$action, &$args)
    {
        parent::before_filter($action, $args);

        global $perm;

        $this->course_id = $_SESSION['SessSemName'][1];
        $this->course_title = $_SESSION['SessSemName'][0];
        $this->user_id = $GLOBALS['auth']->auth['uid'];


        // Check dozent-perms
        if ($perm->have_studip_perm('dozent', $this->course_id)) {
            $this->is_dozent = true;
        }

        // Check tutor-perms
        if ($perm->have_studip_perm('tutor', $this->course_id)) {
            $this->is_tutor = true;
        }

        // Check autor-perms
        if ($perm->have_studip_perm('autor', $this->course_id)) {
            $this->is_autor = true;
        }


        if ($this->is_tutor) {
            PageLayout::setHelpKeyword("Basis.VeranstaltungenVerwaltenTeilnehmer");
        } else {
            PageLayout::setHelpKeyword("Basis.InVeranstaltungTeilnehmer");
        }

        // Check lock rules
        $this->dozent_is_locked = LockRules::Check($this->course_id, 'dozent');
        $this->tutor_is_locked = LockRules::Check($this->course_id, 'tutor');
        $this->is_locked = LockRules::Check($this->course_id, 'participants');


        // Layoutsettings
        PageLayout::setTitle(sprintf('%s - %s', Course::findCurrent()->getFullname(), _("TeilnehmerInnen")));
        PageLayout::addScript('members.js');

        SkipLinks::addIndex(Navigation::getItem('/course/members')->getTitle(), 'main_content', 100);

        if (Request::isXhr()) {
            $this->set_content_type('text/html;charset=windows-1252');
            $this->set_layout(null);
        } else {
            $this->set_layout($GLOBALS['template_factory']->open('layouts/base'));
        }

        checkObject();
        checkObjectModule("participants");
        object_set_visit_module('participants');
        $this->last_visitdate = object_get_visit($this->course_id, 'participants');

        // Check perms and set the last visit date
        if (!$this->is_tutor) {
            $this->last_visitdate = time() + 10;
        }


        // Get the max-page-value for the pagination
        $this->max_per_page = Config::get()->ENTRIES_PER_PAGE;
        $this->status_groups = array(
            'dozent' => get_title_for_status('dozent', 2),
            'tutor' => get_title_for_status('tutor', 2),
            'autor' => get_title_for_status('autor', 2),
            'user' => get_title_for_status('user', 2),
            'accepted' => get_title_for_status('accepted', 2),
            'awaiting' => _("Wartende NutzerInnen"),
            'claiming' => _("Wartende NutzerInnen")
        );

        // StatusGroups for the view
        $this->decoratedStatusGroups = array(
            'dozent' => get_title_for_status('dozent', 1),
            'autor' => get_title_for_status('autor', 1),
            'tutor' => get_title_for_status('tutor', 1),
            'user' => get_title_for_status('user', 1)
        );

        //check for admission / waiting list
        update_admission($this->course_id);

        // Create new MembersModel, to get additionanl informations to a given Seminar
        $this->members = new MembersModel($this->course_id, $this->course_title);
        $this->members->checkUserVisibility();
        
        // Set default sidebar image
        $sidebar = Sidebar::get();
        $sidebar->setImage('sidebar/person-sidebar.png');
    }

    function index_action()
    {
        global $perm, $PATH_EXPORT;
        $sem = Seminar::getInstance($this->course_id);

        // old message style
        if ($_SESSION['sms_msg']) {
            $this->msg = $_SESSION['sms_msg'];
            unset($_SESSION['sms_msg']);
        }

        $this->sort_by = Request::option('sortby', 'nachname');
        $this->order = Request::option('order', 'desc');
        $this->sort_status = Request::get('sort_status');

        if (Request::int('toggle')) {
            $this->order = $this->order == 'desc' ? 'asc' : 'desc';
        }

        $filtered_members = $this->members->getMembers($this->sort_status, $this->sort_by . ' ' . $this->order, !$this->is_tutor ? $this->user_id : null);

        if ($this->is_tutor) {
            $filtered_members = array_merge($filtered_members, $this->members->getAdmissionMembers($this->sort_status, $this->sort_by . ' ' . $this->order ));
            $this->awaiting = $filtered_members['awaiting']->toArray('user_id username vorname nachname visible mkdate');
            $this->accepted = $filtered_members['accepted']->toArray('user_id username vorname nachname visible mkdate');
            $this->claiming = $filtered_members['claiming']->toArray('user_id username vorname nachname visible mkdate');
        }

        // Check autor-perms
        if (!$this->is_tutor) {
            SkipLinks::addIndex(_("Sichtbarkeit �ndern"), 'change_visibility');
            // filter invisible user
            $this->invisibles = count($filtered_members['autor']->findBy('visible', 'no')) + count($filtered_members['user']->findBy('visible', 'no'));
            $current_user_id = $this->user_id;
            $exclude_invisibles =
                    function ($user) use ($current_user_id) {
                        return ($user['visible'] != 'no' || $user['user_id'] == $current_user_id);
                    };
            $filtered_members['autor'] = $filtered_members['autor']->filter($exclude_invisibles);
            $filtered_members['user'] = $filtered_members['user']->filter($exclude_invisibles);
            $this->my_visibility = $this->getUserVisibility();
            if (!$this->my_visibility['iam_visible']) {
                $this->invisibles--;
            }
        }

        // get member informations
        $this->dozenten = $filtered_members['dozent']->toArray('user_id username vorname nachname');
        $this->tutoren = $filtered_members['tutor']->toArray('user_id username vorname nachname mkdate');
        $this->autoren = $filtered_members['autor']->toArray('user_id username vorname nachname visible mkdate');
        $this->users = $filtered_members['user']->toArray('user_id username vorname nachname visible mkdate');
        $this->studipticket = Seminar_Session::get_ticket();
        $this->subject = $this->getSubject();
        $this->groups = $this->status_groups;
        // Check Seminar
        if ($this->is_tutor && $sem->isAdmissionEnabled()) {
            $this->course = $sem;
            $distribution_time = $sem->getCourseSet()->getSeatDistributionTime();
            if ($sem->getCourseSet()->hasAlgorithmRun()) {
                $this->waitingTitle = _("Warteliste");
                if (!$sem->admission_disable_waitlist_move) {
                    $this->waitingTitle .= ' (' . _("automatisches Nachr�cken ist eingeschaltet") . ')';
                } else {
                    $this->waitingTitle .= ' (' . _("automatisches Nachr�cken ist ausgeschaltet") . ')';
                }
                $this->semAdmissionEnabled = 2;
                $this->waiting_type = 'awaiting';
            } else {
                $this->waitingTitle = sprintf(_("Anmeldeliste (Losverfahren am %s)"), strftime('%x %R', $distribution_time));
                $this->semAdmissionEnabled = 1;
                $this->awaiting = $this->claiming;
                $this->waiting_type = 'claiming';
            }
        }
        // Set the infobox
        $this->createSidebar($filtered_members);

        if ($this->is_locked && $this->is_tutor) {
            $lockdata = LockRules::getObjectRule($this->course_id);
            if ($lockdata['description']) {
                PageLayout::postMessage(MessageBox::info(formatLinks($lockdata['description'])));
            }
        }
    }

    /*
     * Returns an array with emails of members
     */
    public function getEmailLinkByStatus($status, $members)
    {
        if (!get_config('ENABLE_EMAIL_TO_STATUSGROUP')) {
            return;
        }

        if (in_array($status, words('accepted awaiting claiming'))) {
            $textStatus = _('Wartenden');
        } else {
            $textStatus = $this->status_groups[$status];
        }

        $results = SimpleCollection::createFromArray($members)->pluck('email');

        if (!empty($results)) {
            return sprintf('<a href="mailto:%s">%s</a>', htmlReady(join(',', $results)), Assets::img('icons/16/blue/move_right/mail.png', tooltip2(sprintf('E-Mail an alle %s versenden', $textStatus))));
        } else {
            return null;
        }
    }

    /**
     * Show dialog to enter a comment for this user
     * @param String $user_id
     * @throws AccessDeniedException
     */
    function add_comment_action($user_id = null) {
        // Security Check
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }

        if (is_null($user_id)) {
            $this->redirect('course/members/index');
            return;
        }
        $this->comment = CourseMember::find(array($this->course_id, $user_id))->comment;
        $this->user = User::find($user_id);
        $this->title = sprintf(_('Bemerkung f�r %s eintragen'), $this->user->getFullName());

        // Output as dialog (Ajax-Request) or as Stud.IP page?
        if (Request::isXhr()) {
            $this->set_layout(null);
            $this->comment = studip_utf8encode($this->comment);
            header('X-Title: ' . $this->title);
        }
    }

    /**
     * Store a comment for this user
     * @param String $user_id
     * @throws AccessDeniedException
     */
    function set_comment_action($user_id = null) {
        // Security Check
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }

        if (!Request::submitted('save') || is_null($user_id)) {
            $this->redirect('course/members/index');
            return;
        }
        CSRFProtection::verifyUnsafeRequest();
        $course = CourseMember::find(array($this->course_id, $user_id));
        $course->comment = Request::get('comment');

        if ($course->store() !== false) {
            PageLayout::postMessage(MessageBox::success(_('Bemerkung wurde erfolgreich gespeichert.')));
        } else {
            PageLayout::postMessage(MessageBox::error(_('Bemerkung konnte nicht erfolgreich gespeichert werden.')));
        }

        $this->redirect('course/members/index');
    }
    
    /**
     * Add members to a seminar.
     * @throws AccessDeniedException
     */
    function execute_multipersonsearch_autor_action()
    {
        // Security Check
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }
        
        // load MultiPersonSearch object
        $mp = MultiPersonSearch::load("add_autor" . $this->course_id);
        $sem = Seminar::GetInstance($this->course_id);
        
        $countAdded = 0;
        foreach ($mp->getAddedUsers() as $a) {
            $msg = $this->members->addMember($a, 'autor', Request::get('consider_contingent'));
            $countAdded++;
        }

        if ($countAdded == 1) {
            $text = _("Es wurde ein/e neue/r AutorIn hinzugef�gt.");
        } else {
            $text = sprintf(_("Es wurden %s neue AutorenInnen hinzugef�gt."), $countAdded);
        }
        PageLayout::postMessage(MessageBox::success($text));
        $this->redirect('course/members/index');
    }
    
     /**
     * Add dozents to a seminar.
     * @throws AccessDeniedException
     */
    function execute_multipersonsearch_dozent_action()
    {
        // Security Check
        if (!$this->is_dozent) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }
        
        // load MultiPersonSearch object
        $mp = MultiPersonSearch::load("add_dozent" . $this->course_id);
        $fail = false;
        foreach ($mp->getAddedUsers() as $a) {
            $result = $this->addDozent($a);
            if ($result !== false) {
                PageLayout::postMessage($result);
            } else {
                $fail = true;
            }
        }
        // only show an error messagebox once.
        if ($fail === true) {
            PageLayout::postMessage(MessageBox::error(_('Die gew�nschte Operation konnte nicht ausgef�hrt werden.')));
        }
        
        $this->redirect('course/members/index');
    }
    
    /**
     * Helper function to add dozents to a seminar.
     */
    private function addDozent($dozent) {
        $deputies_enabled = get_config('DEPUTIES_ENABLE');
        $sem = Seminar::GetInstance($this->course_id);
        if ($sem->addMember($dozent, "dozent")) {
            // Only applicable when globally enabled and user deputies enabled too
            if ($deputies_enabled) {
                // Check whether chosen person is set as deputy
                // -> delete deputy entry.
                if (isDeputy($dozent, $this->course_id)) {
                    deleteDeputy($dozent, $this->course_id);
                }
                // Add default deputies of the chosen lecturer...
                if (get_config('DEPUTIES_DEFAULTENTRY_ENABLE')) {
                    $deputies = getDeputies($dozent);
                    $lecturers = $sem->getMembers('dozent');
                    foreach ($deputies as $deputy) {
                        // ..but only if not already set as lecturer or deputy.
                        if (!isset($lecturers[$deputy['user_id']]) &&
                                !isDeputy($deputy['user_id'], $this->course_id)) {
                            addDeputy($deputy['user_id'], $this->course_id);
                        }
                    }
                }
            }
            // new dozent was successfully insert
            
            return MessageBox::success(sprintf(_('%s wurde hinzugef�gt.'), get_title_for_status('dozent', 1, $sem->status)));
        } else {
            // sorry that was a fail
            return false;
            
        }
    }
    
    /**
     * Add tutors to a seminar.
     * @throws AccessDeniedException
     */
    function execute_multipersonsearch_tutor_action()
    {
        // Security Check
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }
        
        // load MultiPersonSearch object
        $mp = MultiPersonSearch::load("add_tutor" . $this->course_id);
        
        foreach ($mp->getAddedUsers() as $a) {
            $this->addTutor($a);
        }
        $this->redirect('course/members/index');
    }
    
    private function addTutor($tutor) {
        $sem = Seminar::GetInstance($this->course_id);
        if ($sem->addMember($tutor, "tutor")) {
            PageLayout::postMessage(MessageBox::success(sprintf(_('%s wurde hinzugef�gt.'), get_title_for_status('tutor', 1, $sem->status))));
        } else {
            // sorry that was a fail
            PageLayout::postMessage(MessageBox::error(_('Die gew�nsche Operation konnte nicht ausgef�hrt werden')));
        }
    }

    /**
     * Send Stud.IP-Message to selected users
     */
    function send_message_action()
    {
        if (!empty($this->flash['users'])) {
            // create a usable array
            foreach ($this->flash['users'] as $user => $val) {
                if ($val) {
                    $users[] = User::find($user)->username;
                }
            }
            $_SESSION['sms_data'] = array();
            $_SESSION['sms_data']['p_rec'] = array_filter($users);
            $this->redirect(URLHelper::getURL('dispatch.php/messages/write', array('default_subject' => $this->getSubject(), 'tmpsavesnd' => 1)));
        } else {
            $this->redirect('course/members/index');
        }
    }
    
    
    function import_autorlist_action() {
        global $perm;
        if (!Request::isXhr()) {
            Navigation::activateItem('/course/members/view');
        }
        $datafields = DataFieldStructure::getDataFieldStructures('user', (1 | 2 | 4 | 8), true);
        foreach ($datafields as $df) {
            if ($df->accessAllowed($perm) && in_array($df->getId(), $GLOBALS['TEILNEHMER_IMPORT_DATAFIELDS'])) {
                $accessible_df[] = $df;
            }
        }
        $this->accessible_df = $accessible_df;
        
    }
    
    /**
     * Old version of CSV import (copy and paste from teilnehmer.php
     * @global Object $perm
     * @return type
     * @throws AccessDeniedException
     */
    function set_autor_csv_action()
    {
        global $perm;
        // Security Check
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }
        CSRFProtection::verifyUnsafeRequest();

        // prepare CSV-Lines
        $messaging = new messaging();
        $csv_request = preg_split('/(\n\r|\r\n|\n|\r)/', trim(Request::get('csv_import')));
        $csv_mult_founds = array();
        $csv_count_insert = 0;
        $csv_count_multiple = 0;
        $datafield_id = null;

        if (Request::get('csv_import_format') && !in_array(Request::get('csv_import_format'), words('realname username'))) {
            foreach (DataFieldStructure::getDataFieldStructures('user', (1 | 2 | 4 | 8), true) as $df) {
                if ($df->accessAllowed($perm) && in_array($df->getId(), $GLOBALS['TEILNEHMER_IMPORT_DATAFIELDS']) && $df->getId() == Request::quoted('csv_import_format')) {
                    $datafield_id = $df->getId();
                    break;
                }
            }
        }

        if (Request::get('csv_import')) {
            // remove duplicate users from csv-import
            $csv_lines = array_unique($csv_request);
            $csv_count_contingent_full = 0;

            foreach ($csv_lines as $csv_line) {
                $csv_name = preg_split('/[,\t]/', substr($csv_line, 0, 100), -1, PREG_SPLIT_NO_EMPTY);
                $csv_nachname = trim($csv_name[0]);
                $csv_vorname = trim($csv_name[1]);

                if ($csv_nachname) {
                    if (Request::quoted('csv_import_format') == 'realname') {
                        $csv_users = $this->members->getMemberByIdentification($csv_nachname, $csv_vorname);
                    } elseif (Request::quoted('csv_import_format') == 'username') {
                        $csv_users = $this->members->getMemberByUsername($csv_nachname);
                    } else {
                        $csv_users = $this->members->getMemberByDatafield($csv_nachname, $datafield_id);
                    }
                }

                // if found more then one result to given name
                if (count($csv_users) > 1) {

                    // if user have two accounts
                    $csv_count_present = 0;
                    foreach ($csv_users as $row) {

                        if ($row['is_present']) {
                            $csv_count_present++;
                        } else {
                            $csv_mult_founds[$csv_line][] = $row;
                        }
                    }

                    if (is_array($csv_mult_founds[$csv_line])) {
                        $csv_count_multiple++;
                    }
                } elseif (count($csv_users) > 0) {
                    $row = reset($csv_users);
                    if (!$row['is_present']) {
                        $consider_contingent = Request::option('consider_contingent_csv');

                        if (insert_seminar_user($this->course_id, $row['user_id'], 'autor', isset($consider_contingent), $consider_contingent)) {
                            $csv_count_insert++;
                            setTempLanguage($this->user_id);

                            $message = sprintf(_('Sie wurden als TeilnehmerIn in die Veranstaltung **%s** eingetragen.'), $this->course_title);

                            restoreLanguage();
                            $messaging->insert_message($message, $row['username'], '____%system%____', FALSE, FALSE, '1', FALSE, sprintf('%s %s', _('Systemnachricht:'), _('Eintragung in Veranstaltung')), TRUE);
                        } elseif (isset($consider_contingent)) {
                            $csv_count_contingent_full++;
                        }
                    } else {
                        $csv_count_present++;
                    }
                } else {
                    // not found
                    $csv_not_found[] = stripslashes($csv_nachname) . ($csv_vorname ? ', ' . stripslashes($csv_vorname) : '');
                }
            }
        }
        $selected_users = Request::getArray('selected_users');

        if (!empty($selected_users) && count($selected_users) > 0) {
            foreach ($selected_users as $selected_user) {
                if ($selected_user) {
                    if (insert_seminar_user($this->course_id, get_userid($selected_user), 'autor', isset($consider_contingent), $consider_contingent)) {
                        $csv_count_insert++;
                        setTempLanguage($this->user_id);
                        if ($GLOBALS['SEM_CLASS'][$GLOBALS['SEM_TYPE'][$_SESSION['SessSemName']['art_num']]['class']]['workgroup_mode']) {
                            $message = sprintf(_('Sie wurden von einem/r LeiterIn oder AdministratorIn als
                                TeilnehmerIn in die Veranstaltung **%s** eingetragen.'), $this->course_title);
                        } else {
                            $message = sprintf(_('Sie wurden vom einem/r DozentIn oder AdministratorIn als
                                TeilnehmerIn in die Veranstaltung **%s** eingetragen.'), $this->course_title);
                        }
                        restoreLanguage();
                        $messaging->insert_message($message, $selected_user, '____%system%____', FALSE, FALSE, '1', FALSE, sprintf('%s %s', _('Systemnachricht:'), _('Eintragung in Veranstaltung')), TRUE);
                    } elseif (isset($consider_contingent)) {
                        $csv_count_contingent_full++;
                    }
                }
            }
        }

        // no results
        if (!sizeof($csv_lines) && !sizeof($selected_users)) {
            PageLayout::postMessage(MessageBox::error(_("Keine NutzerIn gefunden!")));
        }

        if ($csv_count_insert) {
            PageLayout::postMessage(MessageBox::success(sprintf(_('%s NutzerInnen als AutorIn in die Veranstaltung
                eingetragen!'), $csv_count_insert)));
        }

        if ($csv_count_present) {
            PageLayout::postMessage(MessageBox::info(sprintf(_('%s NutzerInnen waren bereits in der Veranstaltung
                eingetragen!'), $csv_count_present)));
        }

        // redirect to manual assignment
        if ($csv_mult_founds) {
            PageLayout::postMessage(MessageBox::info(sprintf(_('%s NutzerInnen konnten <b>nicht eindeutig</b>
                zugeordnet werden! Nehmen Sie die Zuordnung manuell vor.'), $csv_count_multiple)));
            $this->flash['csv_mult_founds'] = $csv_mult_founds;
            $this->redirect('course/members/csv_manual_assignment');
            return;
        }
        if (count($csv_not_found) > 0) {
            PageLayout::postMessage(MessageBox::error(sprintf(_('%s konnten <b>nicht</b> zugeordnet werden!'), htmlReady(join(',', $csv_not_found)))));
        }

        if ($csv_count_contingent_full) {
            PageLayout::postMessage(MessageBox::error(sprintf(_('%s NutzerInnen konnten <b>nicht</b> zugeordnet werden,
                da das ausgew�hlte Kontingent keine freien Pl�tze hat.'), $csv_count_contingent_full)));
        }

        $this->redirect('course/members/index');
    }

    /**
     * Select manual the assignment of a given user or of a group of users
     * @global Object $perm
     * @throws AccessDeniedException
     */
    function csv_manual_assignment_action()
    {
        global $perm;
        // Security. If user not autor, then redirect to index
        if (!$perm->have_studip_perm('tutor', $this->course_id)) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }

        if (empty($this->flash['csv_mult_founds'])) {
            $this->redirect('course/members/index');
        }
    }

    /**
     * Change the visibilty of an autor
     * @return Boolean
     */
    function change_visibility_action($cmd, $mode)
    {
        global $perm;
        // Security. If user not autor, then redirect to index
        if ($perm->have_studip_perm('tutor', $this->course_id)) {
            throw new AccessDeniedException('Sie haben keine ausreichende Berechtigung,
                um auf diesen Teil des Systems zuzugreifen');
        }

        // Check for visibile mode
        if ($cmd == 'make_visible') {
            $command = 'yes';
        } else {
            $command = 'no';
        }

        if ($mode == 'awaiting') {
            $result = $this->members->setAdmissionVisibility($this->user_id, $command);
        } else {
            $result = $this->members->setVisibilty($this->user_id, $command);
        }

        if ($result > 0) {
            PageLayout::postMessage(MessageBox::success(_('Ihre Sichtbarkeit wurde erfolgreich ge�ndert.')));
        } else {
            PageLayout::postMessage(MessageBox::error(_('Leider ist beim �ndern der Sichtbarkeit ein Fehler aufgetreten. Die Einstellung konnte nicht vorgenommen werden.')));
        }
        $this->redirect('course/members');
    }

    /**
     * Helper function to select the action
     * @throws AccessDeniedException
     */
    function edit_tutor_action()
    {
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }
        CSRFProtection::verifyUnsafeRequest();

        $this->flash['users'] = Request::getArray('tutor');

        // select the additional method
        switch (Request::get('action_tutor')) {
            case '':
                $this->redirect('course/members/index');
                break;
            case 'downgrade':
                $this->redirect('course/members/downgrade_user/tutor/autor');
                break;
            case 'remove':
                $this->redirect('course/members/cancel_subscription/collection/tutor');
                break;
            case 'message':
                $this->redirect('course/members/send_message');
                break;
            default:
                $this->redirect('course/members/index');
                break;
        }
    }

    /**
     * Helper function to select the action
     * @throws AccessDeniedException
     */
    function edit_autor_action()
    {
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }
        CSRFProtection::verifyUnsafeRequest();

        $this->flash['users'] = Request::getArray('autor');

        switch (Request::get('action_autor')) {
            case '':
                $this->redirect('course/members/index');
                break;
            case 'upgrade':
                $this->redirect('course/members/upgrade_user/autor/tutor');
                break;
            case 'downgrade':
                $this->redirect('course/members/downgrade_user/autor/user');
                break;
            case 'to_admission':
                // TODO Warteliste setzen
                break;
            case 'remove':
                $this->redirect('course/members/cancel_subscription/collection/autor');
                break;
            case 'message':
                $this->redirect('course/members/send_message');
                break;
            default:
                $this->redirect('course/members/index');
                break;
        }
    }

    /**
     * Helper function to select the action
     * @throws AccessDeniedException
     */
    function edit_user_action()
    {
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben keine ausreichende Berechtigung,
                um auf diesen Teil des Systems zuzugreifen');
        }
        CSRFProtection::verifyUnsafeRequest();

        $this->flash['users'] = Request::getArray('user');
        $this->flash['consider_contingent'] = Request::get('consider_contingent');

        // select the additional method
        switch (Request::get('action_user')) {
            case '':
                $this->redirect('course/members/index');
                break;
            case 'upgrade':
                $this->redirect('course/members/upgrade_user/user/autor');
                break;
            case 'remove':
                $this->redirect('course/members/cancel_subscription/collection/user');
                break;
            case 'message':
                $this->redirect('course/members/send_message');
                break;
            default:
                $this->redirect('course/members/index');
                break;
        }
    }

    /**
     * Helper function to select the action
     * @throws AccessDeniedException
     */
    function edit_awaiting_action()
    {
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }
        CSRFProtection::verifyUnsafeRequest();

        $this->flash['users'] = Request::getArray('awaiting');
        $this->flash['consider_contingent'] = Request::get('consider_contingent');
        $waiting_type = Request::option('waiting_type');
        // select the additional method
        switch (Request::get('action_awaiting')) {
            case '':
                $this->redirect('course/members/index');
                break;
            case 'upgrade':
                $this->redirect('course/members/insert_admission/awaiting/collection');
                break;
            case 'remove':
                $this->redirect('course/members/cancel_subscription/collection/' . $waiting_type);
                break;
            case 'message':
                $this->redirect('course/members/send_message');
                break;
            default:
                $this->redirect('course/members/index');
                break;
        }
    }

    /**
     * Helper function to select the action
     * @throws AccessDeniedException
     */
    function edit_accepted_action()
    {
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben keine ausreichende Berechtigung,
                um auf diesen Teil des Systems zuzugreifen');
        }
        CSRFProtection::verifyUnsafeRequest();

        $this->flash['users'] = Request::getArray('accepted');
        $this->flash['consider_contingent'] = Request::get('consider_contingent');


        // select the additional method
        switch (Request::get('action_accepted')) {
            case '':
                $this->redirect('course/members/index');
                break;
            case 'upgrade':
                $this->redirect('course/members/insert_admission/accepted/collection');
                break;
            case 'remove':
                $this->redirect('course/members/cancel_subscription/collection/accepted');
                break;
            case 'message':
                $this->redirect('course/members/send_message');
                break;
            default:
                $this->redirect('course/members/index');
                break;
        }
    }

    /**
     * Insert a user to a given seminar or a group of users
     * @param String $status
     * @param String $cmd
     * @param String $user_id
     * @return String
     * @throws AccessDeniedException
     */
    function insert_admission_action($status, $cmd)
    {
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }

        if (isset($this->flash['consider_contingent'])) {
            Request::set('consider_contingent', $this->flash['consider_contingent']);
        }

        // create a usable array
        $users = array_filter($this->flash['users'], function ($user) {
                    return $user;
                });

        if ($users) {
            $msgs = $this->members->insertAdmissionMember($users, 'autor', Request::get('consider_contingent'), $status == 'accepted');
            if ($msgs) {
                if ($cmd == 'add_user') {
                    $message = sprintf(_('%s wurde in die Veranstaltung mit dem Status <b>%s</b> eingetragen.'), htmlReady(join(',', $msgs)), $this->decoratedStatusGroups['autor']);
                } else {
                    if ($status == 'awaiting') {
                        $message = sprintf(_('%s wurde aus der Anmelde bzw. Warteliste mit dem Status
                            <b>%s</b> in die Veranstaltung eingetragen.'), htmlReady(join(', ', $msgs)), $this->decoratedStatusGroups['autor']);
                    } else {
                        $message = sprintf(_('%s wurde mit dem Status <b>%s</b> endg�ltig akzeptiert
                            und damit in die Veranstaltung aufgenommen.'), htmlReady(join(', ', $msgs)), $this->decoratedStatusGroups['autor']);
                    }
                }

                PageLayout::postMessage(MessageBox::success($message));
            } else {
                $message = _("Es stehen keine weiteren Pl�tze mehr im Teilnehmerkontingent zur Verf�gung.");
                PageLayout::postMessage(MessageBox::error($message));
            }
        } else {
            PageLayout::postMessage(MessageBox::error(_('Sie haben keine Nutzer zum Hochstufen ausgew�hlt.')));
        }

        $this->redirect('course/members/index');
    }

    /**
     * Cancel the subscription of a selected user or group of users
     * @param String $cmd
     * @param String $status
     * @param String $user_id
     * @throws AccessDeniedException
     */
    function cancel_subscription_action($cmd, $status, $user_id = null)
    {
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben keine ausreichende Berechtigung,
                um auf diesen Teil des Systems zuzugreifen');
        }

        if (!Request::submitted('no')) {

            if (Request::submitted('yes')) {
                CSRFProtection::verifyUnsafeRequest();
                $users = Request::getArray('users');
                if (!empty($users)) {
                    if (in_array($status, words('accepted awaiting claiming'))) {
                        $msgs = $this->members->cancelAdmissionSubscription($users, $status);
                    } else {
                        $msgs = $this->members->cancelSubscription($users);
                    }

                    // deleted authors
                    if (!empty($msgs)) {
                        if (count($msgs) <= 5) {
                            PageLayout::postMessage(MessageBox::success(sprintf(_("%s %s wurde aus der Veranstaltung ausgetragen."), htmlReady($this->status_groups[$status]), htmlReady(join(', ', $msgs)))));
                        } else {
                            PageLayout::postMessage(MessageBox::success(sprintf(_("%u %s wurden aus der Veranstaltung entfernt."), count($msgs), htmlReady($this->status_groups[$status]))));
                        }
                    }
                } else {
                    PageLayout::postMessage(MessageBox::error(sprintf(_('Sie haben keine %s zum Austragen ausgew�hlt')), $this->status_groups[$status]));
                }
            } else {
                if ($cmd == "singleuser") {
                    $users = array($user_id);
                } else {
                    // create a usable array
                    foreach ($this->flash['users'] as $user => $val) {
                        if ($val) {
                            $users[] = $user;
                        }
                    }
                }
                $this->flash['status'] = $status;
                $this->flash['delete'] = $users;
            }
        }



        $this->redirect('course/members/index');
    }

    /**
     * Upgrade a user to a selected status
     * @param type $status
     * @param type $next_status
     * @param type $username
     * @param type $cmd
     * @throws AccessDeniedException
     */
    function upgrade_user_action($status, $next_status)
    {
        global $perm;

        // Security Check
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben leider keine ausreichende Berechtigung, um auf diesen Bereich von Stud.IP zuzugreifen.');
        }

        if ($this->is_tutor && $perm->have_studip_perm('tutor', $this->course_id) && $next_status != 'autor' && !$perm->have_studip_perm('dozent', $this->course_id)) {
            throw new AccessDeniedException('Sie haben keine ausreichende Berechtigung,
                um auf diesen Teil des Systems zuzugreifen');
        }

        // create a usable array
        // TODO: arrayFilter
        foreach ($this->flash['users'] as $user => $val) {
            if ($val) {
                $users[] = $user;
            }
        }

        if (!empty($users)) {
            // insert admission user to autorlist
            $msgs = $this->members->setMemberStatus($users, $status, $next_status, 'upgrade');

            if ($msgs['success']) {
                PageLayout::postMessage(MessageBox::success(sprintf(_('Das Hochstufen auf den Status  %s von %s
                    wurde erfolgreich durchgef&uuml;hrt'), htmlReady($this->decoratedStatusGroups[$next_status]), htmlReady(join(', ', $msgs['success'])))));
            }

            if ($msgs['no_tutor']) {
                PageLayout::postMessage(MessageBox::error(sprintf(_('Das Hochstufen auf den Status  %s von %s
                   konnte wegen fehlender Rechte nicht durchgef&uuml;hrt werden.'), htmlReady($this->decoratedStatusGroups[$next_status]), htmlReady(join(', ', $msgs['no_tutor'])))));
            }
        } else {
            PageLayout::postMessage(MessageBox::error(sprintf(_('Sie haben keine %s zum Hochstufen ausgew�hlt'), htmlReady($this->status_groups[$status]))));
        }

        $this->redirect('course/members/index');
    }

    /**
     * Downgrade a user to a selected status
     * @param type $status
     * @param type $next_status
     * @param type $username
     * @param type $cmd
     * @throws AccessDeniedException
     */
    function downgrade_user_action($status, $next_status)
    {
        // Security Check
        if (!$this->is_tutor) {
            throw new AccessDeniedException('Sie haben keine ausreichende Berechtigung,
                um auf diesen Teil des Systems zuzugreifen');
        }
        // TODO: Check this
        if ($this->is_tutor && $next_status != 'user' && !$this->is_dozent) {
            throw new AccessDeniedException('Sie haben keine ausreichende Berechtigung,
                um auf diesen Teil des Systems zuzugreifen');
        }

        foreach ($this->flash['users'] as $user => $val) {
            if ($val) {
                $users[] = $user;
            }
        }

        if (!empty($users)) {
            $msgs = $this->members->setMemberStatus($users, $status, $next_status, 'downgrade');

            if ($msgs['success']) {
                PageLayout::postMessage(MessageBox::success(sprintf(_('Der/die %s %s wurde auf den
                    Status %s heruntergestuft.'), htmlReady($this->decoratedStatusGroups[$status]), htmlReady(join(', ', $msgs['success'])), $this->decoratedStatusGroups[$next_status])));
            }
        } else {
            PageLayout::postMessage(MessageBox::error(sprintf(_('Sie haben keine %s zum Herunterstufen ausgew�hlt'), htmlReady($this->status_groups[$status]))));
        }

        $this->redirect('course/members/index');
    }

    /**
     * Displays all members of the course and their aux data
     * @return int fake return to stop after redirect;
     */
    function additional_action($format = null) {

        // Users get forwarded to aux_input
        if (!($this->is_dozent || $this->is_tutor)) {
            $this->redirect('course/members/additional_input');
            return 0;
        }

        // fetch course and aux data
        $course = new Course($_SESSION['SessionSeminar']);
        if (Request::submitted('save')) {
            foreach ($course->members->findBy('status', 'autor') as $member) {
                $course->aux->updateMember($member, Request::getArray($member->user_id));
            }
        }       
        if (Request::submitted('export')) {
            $aux = $course->aux->getCourseData($course, true);
            $doc = new exportDoc();
            $table = $doc->add('table');
            $table->header = $aux['head'];
            $table->content = $aux['rows'];
            $doc->export('XLS');
        } else {
            $this->aux = $course->aux->getCourseData($course);
        }
    }

    /**
     * Aux input for users
     */
    function additional_input_action() {

        // Activate the autoNavi otherwise we dont find this page in navi
        Navigation::activateItem('/course/members/additional');

        // Fetch datafields for the user
        $course = new Course($_SESSION['SessionSeminar']);
        $member = $course->members->findOneBy('user_id', $GLOBALS['user']->id);
        $this->datafields = $course->aux->getMemberData($member);

        // We need aux data in the view
        $this->aux = $course->aux;

        // Update em if they got submittet
        if (Request::submitted('save')) {
            $datafields = SimpleCollection::createFromArray($this->datafields);
            foreach (Request::getArray('aux') as $aux => $value) {
                $datafield = $datafields->findOneBy('datafield_id', $aux);
                if ($datafield) {
                    $typed = $datafield->getTypedDatafield();
                    if ($typed->isEditable()) {
                        $typed->setValueFromSubmit($value);
                        $typed->store();
                    }
                }
            }
        }
    }

    /**
     * Get the visibility of a user in a seminar
     * @param String $user_id
     * @param String $seminar_id
     * @return Array
     */
    private function getUserVisibility()
    {
        $member = CourseMember::find(array($this->course_id, $this->user_id));

        $visibility = $member->visible;
        $status = $member->status;
        #echo "<pre>"; var_dump($member); echo "</pre>";
        $result['visible_mode'] = false;

        if ($visibility) {
            $result['iam_visible'] = ($visibility == 'yes' || $visibility == 'unknown');

            if ($status == 'user' || $status == 'autor') {
                $result['visible_mode'] = 'participant';
            } else {
                $result['iam_visible'] = true;
                $result['visible_mode'] = false;
            }
        }

        return $result;
    }

    /**
     * Returns the Subject for the Messaging
     * @return String
     */
    private function getSubject()
    {
        $result = Seminar::GetInstance($this->course_id)->getNumber();

        $subject = ($result == '') ? sprintf('[%s]', $this->course_title) :
                sprintf('[%s] : %s', $result, $this->course_title);

        return $subject;
    }

    private function createSidebar($filtered_members)
    {
        $sidebar = Sidebar::get();
        
        if ($this->is_tutor) {
            $widget = new ActionsWidget();

            $url = URLHelper::getLink('dispatch.php/messages/write', array(
                'course_id' => $this->course_id,
                'default_subject' => $this->subject,
                'filter' => 'all',
                'emailrequest' => 1
            ));
            $widget->addLink(_('Nachricht an alle (Rundmail)'), $url, 'icons/16/black/inbox.png');

            if ($this->is_dozent) {
                if (!$this->dozent_is_locked) {
                    $sem = Seminar::GetInstance($this->course_id);
                    $sem_institutes = $sem->getInstitutes();

                    if (SeminarCategories::getByTypeId($sem->status)->only_inst_user) {
                        $search_template = "user_inst";
                    } else {
                        $search_template = "user";
                    }

                    // create new search for dozent
                    $searchtype = new PermissionSearch(
                            $search_template, sprintf(_("%s suchen"), get_title_for_status('dozent', 1, $sem->status)), "user_id", array('permission' => 'dozent',
                            'exclude_user' => array(),
                            'institute' => $sem_institutes)
                    );

                    
                    // quickfilter: dozents of institut
                    $sql = "SELECT user_id FROM user_inst WHERE Institut_id = ? AND inst_perms = 'dozent'";
                    $db = DBManager::get();
                    $statement = $db->prepare($sql, array(PDO::FETCH_NUM));
                    $statement->execute(array(Seminar::getInstance($this->course_id)->getInstitutId()));
                    $membersOfInstitute = $statement->fetchAll(PDO::FETCH_COLUMN, 0);
                    
                    // add "add dozent" to infobox
                    $mp = MultiPersonSearch::get('add_dozent' . $this->course_id)
                        ->setLinkText(sprintf(_('Neue/n %s eintragen'), $this->status_groups['dozent']))
                        ->setDefaultSelectedUser($filtered_members['dozent']->pluck('user_id'))
                        ->setLinkIconPath("")
                        ->setTitle(sprintf(_('Neue/n %s eintragen'), $this->status_groups['dozent']))
                        ->setExecuteURL(URLHelper::getLink('dispatch.php/course/members/execute_multipersonsearch_dozent'))
                        ->setSearchObject($searchtype)
                        ->addQuickfilter(sprintf(_('%s der Einrichtung'), $this->status_groups['dozent']), $membersOfInstitute)
                        ->render();
                    $element = LinkElement::fromHTML($mp, 'icons/16/black/add/community.png');
                    $widget->addElement($element);
                }
                if (!$this->tutor_is_locked) {
                    $sem = Seminar::GetInstance($this->course_id);
                    $sem_institutes = $sem->getInstitutes();

                    if (SeminarCategories::getByTypeId($sem->status)->only_inst_user) {
                        $search_template = 'user_inst';
                    } else {
                        $search_template = 'user';
                    }
                    
                    // create new search for tutor
                    $searchType = new PermissionSearch(
                            $search_template, sprintf(_('%s suchen'), get_title_for_status('tutor', 1, $sem->status)), 'user_id', array('permission' => array('dozent', 'tutor'),
                        'exclude_user' => array(),
                        'institute' => $sem_institutes)
                    );
                    
                    // quickfilter: tutors of institut
                    $sql = "SELECT user_id FROM user_inst WHERE Institut_id = ? AND inst_perms = 'tutor'";
                    $db = DBManager::get();
                    $statement = $db->prepare($sql, array(PDO::FETCH_NUM));
                    $statement->execute(array(Seminar::getInstance($this->course_id)->getInstitutId()));
                    $membersOfInstitute = $statement->fetchAll(PDO::FETCH_COLUMN, 0);
                    
                    // add "add tutor" to infobox
                    $mp = MultiPersonSearch::get("add_tutor" . $this->course_id)
                        ->setLinkText(sprintf(_('Neue/n %s eintragen'), $this->status_groups['tutor']))
                        ->setDefaultSelectedUser($filtered_members['tutor']->pluck('user_id'))
                        ->setLinkIconPath("")
                        ->setTitle(sprintf(_('Neue/n %s eintragen'), $this->status_groups['tutor']))
                        ->setExecuteURL(URLHelper::getLink('dispatch.php/course/members/execute_multipersonsearch_tutor'))
                        ->setSearchObject($searchType)
                        ->addQuickfilter(sprintf(_('%s der Einrichtung'), $this->status_groups['tutor']), $membersOfInstitute)
                        ->render();
                    $element = LinkElement::fromHTML($mp, 'icons/16/black/add/community.png');
                    $widget->addElement($element);
                }
            }
            if (!$this->is_locked) {
                // create new search for members
                $searchType = new SQLSearch("SELECT auth_user_md5.user_id, CONCAT(" . $GLOBALS['_fullname_sql']['full'] .
                ", \" (\", auth_user_md5.username, \")\") as fullname " .
                "FROM auth_user_md5 " .
                "LEFT JOIN user_info ON (user_info.user_id = auth_user_md5.user_id) " .
                "WHERE (CONCAT(auth_user_md5.Vorname, \" \", auth_user_md5.Nachname) LIKE :input " .
                "OR auth_user_md5.username LIKE :input) " .
                "AND auth_user_md5.perms IN ('autor', 'tutor', 'dozent') " .
                " AND auth_user_md5.visible <> 'never' " .
                "ORDER BY Vorname, Nachname", _("Teilnehmer suchen"), "username");

                // quickfilter: tutors of institut
                $sql = "SELECT user_id FROM user_inst WHERE Institut_id = ? AND inst_perms = 'autor'";
                $db = DBManager::get();
                $statement = $db->prepare($sql, array(PDO::FETCH_NUM));
                $statement->execute(array(Seminar::getInstance($this->course_id)->getInstitutId()));
                $membersOfInstitute = $statement->fetchAll(PDO::FETCH_COLUMN, 0);

                // add "add autor" to infobox
                $mp = MultiPersonSearch::get("add_autor" . $this->course_id)
                    ->setLinkText(sprintf(_('Neue/n %s eintragen'), $this->status_groups['autor']))
                    ->setDefaultSelectedUser($filtered_members['autor']->pluck('user_id'))
                    ->setLinkIconPath("")
                    ->setTitle(sprintf(_('Neue/n %s eintragen'), $this->status_groups['autor']))
                    ->setExecuteURL(URLHelper::getLink('dispatch.php/course/members/execute_multipersonsearch_autor'))
                    ->setSearchObject($searchType)
                    ->addQuickfilter(sprintf(_('%s der Einrichtung'), $this->status_groups['autor']), $membersOfInstitute)
                    ->render();
                $element = LinkElement::fromHTML($mp, 'icons/16/black/add/community.png');
                $widget->addElement($element);
            }

            $widget->addLink(_('Teilnehmerliste importieren'),
                             $this->url_for('course/members/import_autorlist'),
                             'icons/16/black/add/community.png');

            $sidebar->addWidget($widget);
        
            if (Config::get()->EXPORT_ENABLE) {
                include_once $GLOBALS['PATH_EXPORT'] . '/export_linking_func.inc.php';

                $widget = new ExportWidget();

                // create csv-export link
                $csvExport = export_link($this->course_id, "person", sprintf('%s %s', htmlReady($this->status_groups['autor']), htmlReady($this->course_title)), 'csv', 'csv-teiln', '', _('TeilnehmerInnen-Liste als csv-Dokument exportieren'), 'passthrough');
                $widget->addLink(_('TeilnehmerInnen-Liste als csv-Dokument exportieren'),
                                 $this->parseHref($csvExport),
                                 'icons/16/black/export/file-office.png');
                // create csv-export link
                $rtfExport = export_link($this->course_id, "person", sprintf('%s %s', htmlReady($this->status_groups['autor']), htmlReady($this->course_title)), 'rtf', 'rtf-teiln', '', _('TeilnehmerInnen-Liste als rtf-Dokument exportieren'), 'passthrough');
                $widget->addLink(_('TeilnehmerInnen-Liste als rtf-Dokument exportieren'),
                                 $this->parseHref($rtfExport),
                                 'icons/16/black/export/file-text.png');

                if (count($this->awaiting) > 0) {
                    $awaiting_rtf = export_link($this->course_id, "person", sprintf('%s %s', _("Warteliste"), htmlReady($this->course_title)), "rtf", "rtf-warteliste", "awaiting", _("Warteliste als rtf-Dokument exportieren"), 'passthrough');
                    $widget->addLink(_('Warteliste als rtf-Dokument exportieren'),
                                     $this->parseHref($awaiting_rtf),
                                     'icons/16/black/export/file-office.png');

                    $awaiting_csv = export_link($this->course_id, "person", sprintf('%s %s', _("Warteliste"), htmlReady($this->course_title)), "csv", "csv-warteliste", "awaiting", _("Warteliste als csv-Dokument exportieren"), 'passthrough');
                    $widget->addLink(_('Warteliste als csv-Dokument exportieren'),
                                     $this->parseHref($awaiting_csv),
                                     'icons/16/black/export/file-text.png');
                }
            
                $sidebar->addWidget($widget);
            }
        } else {
            // Visibility preferences
            if (!$this->my_visibility['iam_visible']) {
                $text = _('Sie sind f�r andere TeilnehmerInnen auf der TeilnehmerInnen-Liste nicht sichtbar.');
                $icon = 'icons/16/black/visibility-visible.png';
                $modus = 'make_visible';
                $link_text = _('Klicken Sie hier, um sichtbar zu werden.');
            } else {
                $text = _('Sie sind f�r andere TeilnehmerInnen auf der TeilnehmerInnen-Liste sichtbar.');
                $icon = 'icons/16/black/visibility-invisible.png';
                $modus = 'make_invisible';
                $link_text = _('Klicken Sie hier, um unsichtbar zu werden.');
            }

            $actions = new ActionsWidget();
            $actions->addLink($link_text,
                              $this->url_for('course/members/change_visibility', $modus, $this->my_visibility['visible_mode']),
                              $icon,
                              array('title' => $text));
            $sidebar->addWidget($actions);
        }
    }
    
    private function parseHref($string)
    {
        $temp = preg_match('/href="(.*?)"/', $string, $match); // Yes, you're absolutely right - this IS horrible!
        return $match[1];
    }
}
