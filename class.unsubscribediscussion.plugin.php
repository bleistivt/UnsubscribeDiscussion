<?php

use Garden\StaticCacheTranslationTrait;

class UnsubscribeDiscussionPlugin extends \Gdn_Plugin {

    use StaticCacheTranslationTrait;

    //Ajax endpoint for the unsubscribe click
    public function discussionController_unsubscribe_create($sender, $discussionID) {
        if (!Gdn::request()->isAuthenticatedPostBack()) {
            throw permissionException();
        }

        $model = $sender->DiscussionModel;
        if (!$discussion = $model->getID($discussionID)) {
            throw notFoundException('Discussion');
        }

        // WHERE array for convenience
        $where = [
            'DiscussionID' => $discussion->DiscussionID,
            'UserID' => Gdn::session()->UserID
        ];

        // Get the UserDiscussion data.
        $userDiscussion = $model->SQL->getWhere('UserDiscussion', $where)->firstRow();

        if ($userDiscussion) {
            // Update the existing row.
            $unsubscribed = !$userDiscussion->Unsubscribed;
            $model->SQL->put('UserDiscussion', ['Unsubscribed' => (int)$unsubscribed], $where);
        } else {
            // Insert a new row.
            $unsubscribed = true;
            $where['Unsubscribed'] = 1;
            $model->SQL->options('Ignore', true)->insert('UserDiscussion', $where);
        }
        $discussion->Unsubscribed = $unsubscribed;

        if ($unsubscribed) {
            $sender->informMessage(
                sprite('Eye', 'InformSprite').self::t('You will no longer be notified about this discussion.'),
                'Dismissable AutoDismiss HasSprite'
            );
        } else {
            $sender->informMessage(
                sprite('Eye', 'InformSprite').self::t('You will receive new notifications about this discussion.'),
                'Dismissable AutoDismiss HasSprite'
            );
        }

        // Highlight the discussion item.
        $sender->jsonTarget("#Discussion_{$discussion->DiscussionID}", null, 'Highlight');
        $sender->jsonTarget(".Discussion #Item_0", null, 'Highlight');

        // Update the options flyout.
        $sender->sendOptions($discussion);

        $sender->render('blank', 'utility', 'dashboard');
    }


    // Add the "Unsubscribed" field to the discussion queries.
    /*public function discussionModel_beforeGet_handler($sender) {
        if (Gdn::session()->isValid()) {
            $sender->SQL->select('w.Unsubscribed');
        }
    }*/

    public function discussionModel_afterDiscussionSummaryQuery_handler($sender) {
        if (Gdn::session()->isValid()) {
            $sender->SQL->select('w.Unsubscribed');
        }
    }

    /*public function discussionModel_beforeGetAnnouncements_handler($sender) {
        $this->discussionModel_beforeGet_handler($sender);
    }*/


    public function discussionModel_beforeGetID_handler($sender) {
        if (Gdn::session()->isValid()) {
            $sender->SQL->select('w.Unsubscribed');
        }
    }


    // Intercept the notification queue to remove unsubscribed discussion notifications.
    public function commentModel_beforeNotification_handler($sender) {
        // Find out which discussion we are in.
        $record = false;
        foreach (ActivityModel::$Queue as $item) {
            if (isset($item['Comment'][0]['RecordID'])) {
                $record = Gdn::sql()
                    ->getWhere('Comment', ['CommentID' => $item['Comment'][0]['RecordID']])
                    ->firstRow(DATASET_TYPE_ARRAY);
                if ($record) {
                    break;
                }
            }
        }
        if (!$record) {
            return;
        }
        // Get all UserIDs that have unsubscribed the discussion.
        $unsubscribed = Gdn::sql()
            ->select('UserID')
            ->from('UserDiscussion')
            ->where([
                'DiscussionID' => $record['DiscussionID'],
                'Unsubscribed' => 1
            ])
            ->get()
            ->resultArray();
        $unsubscribed = array_column($unsubscribed, 'UserID');

        // Unset all notification queue items for these users.
        foreach (ActivityModel::$Queue as $userID => $item) {
            if (in_array($userID, $unsubscribed)) {
                unset(ActivityModel::$Queue[$userID]);
            }
        }
    }


    // Add the unsubscribe/resubscribe option
    public function base_discussionOptionsDropdown_handler($sender, $args) {
        $session = Gdn::session();
        if (!$session->isValid()) {
            return;
        }
        $discussion = $args['Discussion'];

        // Get the Unsubscribed status, if it is not set.
        // This is only needed for endpoints that do not fire a BeforeGet event.
        if (!isset($discussion->Unsubscribed)) {
            $userDiscussion = Gdn::sql()
                ->select('Unsubscribed')
                ->from('UserDiscussion')
                ->where([
                    'DiscussionID' => $discussion->DiscussionID,
                    'UserID' => $session->UserID
                ])
                ->get()
                ->firstRow();
            $discussion->Unsubscribed = (bool)($userDiscussion->Unsubscribed ?? 0);
        }

        $args['DiscussionOptionsDropdown']->addLinkIf(
            (bool)$discussion->Participated || $discussion->InsertUserID == $session->UserID,
            self::t($discussion->Unsubscribed ? 'Resubscribe' : 'Unsubscribe'),
            '/discussion/unsubscribe/'.$discussion->DiscussionID,
            $discussion->Unsubscribed ? 'resubscribe' : 'unsubscribe',
            $discussion->Unsubscribed ? 'Resubscribe Hijack' : 'Unsubscribe Hijack'
        );
    }


    // Add a new "Unsubscribed" column to the UserDiscussion table.
    public function structure() {
        Gdn::structure()
            ->table('UserDiscussion')
            ->column('Unsubscribed', 'tinyint(1)', '0')
            ->set();
    }


    public function setup() {
        $this->structure();
    }

}
