<?php if (!defined('APPLICATION')) exit();

$PluginInfo['UnsubscribeDiscussion'] = array(
    'Name' => 'Unsubscribe Discussion',
    'Description' => 'Adds the ability to selectively turn off notifications for individual discussions.',
    'Version' => '1.0.1',
    'RequiredApplications' => array('Vanilla' => '2.1'),
    'HasLocale' => true,
    'MobileFriendly' => true,
    'Author' => 'Bleistivt',
    'AuthorUrl' => 'http://bleistivt.net'
);

class UnsubscribeDiscussionPlugin extends Gdn_Plugin {

    //Ajax endpoint for the unsubscribe click
    public function DiscussionController_Unsubscribe_Create($Sender, $Args) {
        $Session = Gdn::Session();
        if (!$Session->UserID) {
            throw PermissionException('SignedIn');
        }
        if (!$Sender->Request->IsAuthenticatedPostBack()
            && !$Session->ValidateTransientKey(val(1, $Args))
        ) {
            throw PermissionException();
        }

        $DiscussionModel = new DiscussionModel();
        $Discussion = $DiscussionModel->GetID(val(0, $Args));
        if (!$Discussion) {
            throw NotFoundException('Discussion');
        }

        // WHERE array for convenience
        $Wheres = array(
            'DiscussionID' => $Discussion->DiscussionID,
            'UserID' => $Session->UserID
        );

        // Get the UserDiscussion data.
        $UserDiscussion = $DiscussionModel->SQL
            ->GetWhere('UserDiscussion', $Wheres)
            ->FirstRow(DATASET_TYPE_ARRAY);

        if ($UserDiscussion) {
            $Unsubscribed = !$UserDiscussion['Unsubscribed'];
            // Update the existing row.
            $DiscussionModel->SQL
                ->Put('UserDiscussion', array('Unsubscribed' => (int)$Unsubscribed), $Wheres);
        } else {
            $Unsubscribed = true;
            $Wheres['Unsubscribed'] = 1;
            // Insert a new row.
            $DiscussionModel->SQL
                ->Options('Ignore', true)
                ->Insert('UserDiscussion', $Wheres);
        }
        $Discussion->Unsubscribed = $Unsubscribed;

        if ($Unsubscribed) {
            $Sender->InformMessage(
                Sprite('Eye', 'InformSprite').T('You will no longer be notified about this discussion.'),
                'Dismissable AutoDismiss HasSprite'
            );
        } else {
            $Sender->InformMessage(
                Sprite('Eye', 'InformSprite').T('You will receive new notifications about this discussion.'),
                'Dismissable AutoDismiss HasSprite'
            );
        }

        // Redirect for non-js users
        if ($Sender->DeliveryType() == DELIVERY_TYPE_ALL) {
            $Target = GetIncomingValue('Target', '/discussions');
            SafeRedirect($Target);
        }

        // Highlight the discussion item.
        $Sender->JsonTarget("#Discussion_{$Discussion->DiscussionID}", null, 'Highlight');
        $Sender->JsonTarget(".Discussion #Item_0", null, 'Highlight');

        // Update the options flyout.
        $Sender->SendOptions($Discussion);

        $Sender->Render('Blank', 'Utility', 'Dashboard');
    }

    // Add the "Unsubscribed" field to the discussion queries.
    public function DiscussionModel_BeforeGet_Handler($Sender) {
        if (Gdn::Session()->UserID > 0) {
            $Sender->SQL->Select('w.Unsubscribed');
        }
    }

    public function DiscussionModel_BeforeGetID_Handler($Sender) {
        if (Gdn::Session()->UserID > 0) {
            $Sender->SQL->Select('w.Unsubscribed');
        }
    }

    // Intercept the notification queue to remove unsubscribed discussion notifications.
    public function CommentModel_BeforeNotification_Handler($Sender) {
        // Find out which discussion we are in.
        $Discussion = false;
        foreach (ActivityModel::$Queue as $QItem) {
            if (isset($QItem['Comment'][0]['RecordID'])) {
                $Record = GetRecord('comment', $QItem['Comment'][0]['RecordID']);
                if ($Record) {
                    $Discussion = $Record['Discussion'];
                    break;
                }
            }
        }
        if (!$Discussion) {
            return;
        }
        // Get all UserIDs that have unsubscribed the discussion.
        $Unsubscribed = Gdn::SQL()
            ->Select('UserID')
            ->From('UserDiscussion')
            ->Where(array(
                'DiscussionID' => $Discussion['DiscussionID'],
                'Unsubscribed' => 1
            ))
            ->Get()
            ->Result(DATASET_TYPE_ARRAY);
        $Unsubscribed = ConsolidateArrayValuesByKey($Unsubscribed, 'UserID');

        // Unset all notification queue items for these users.
        foreach (ActivityModel::$Queue as $UserID => $QItem) {
            if (in_array($UserID, $Unsubscribed)) {
                unset(ActivityModel::$Queue[$UserID]);
            }
        }
    }

    // Add the unsubscribe/resubscribe option
    public function Base_DiscussionOptions_Handler($Sender) {
		$Session = Gdn::Session();
		if (!$Session->IsValid()) {
			return;
		}
        $Discussion = $Sender->EventArguments['Discussion'];

        // Get the Unsubscribed status, if it is not set.
        // This is only needed for announcements, as GetAnnouncements doesn't fire a BeforeGet event.
        if (!isset($Discussion->Unsubscribed)) {
            $UserDiscussion = Gdn::SQL()
                ->Select('Unsubscribed')
                ->From('UserDiscussion')
                ->Where(array(
                    'DiscussionID' => $Discussion->DiscussionID,
                    'UserID' => Gdn::Session()->UserID
                ))
                ->Get()
                ->FirstRow(DATASET_TYPE_ARRAY);
            $Discussion->Unsubscribed = (bool)$UserDiscussion['Unsubscribed'];
        }

        $Option = array(
            'Label' => T($Discussion->Unsubscribed ? 'Resubscribe' : 'Unsubscribe'),
            'Url' => '/discussion/unsubscribe/'
                .$Discussion->DiscussionID.'/'
                .Gdn::Session()->TransientKey()
                .'?Target='.urlencode($Sender->SelfUrl),
            'Class' => $Discussion->Unsubscribed ? 'Resubscribe Hijack' : 'Unsubscribe Hijack'
        );

        if (isset($Sender->EventArguments['DiscussionOptions'])) {
            // Discussion options in discussion view
            $Sender->EventArguments['DiscussionOptions']['UnsubscribeDiscussion'] = $Option;
        } else {
            // Discussion options in discussions view
            $Sender->Options .= Wrap(Anchor($Option['Label'], $Option['Url'], $Option['Class']), 'li');
        }
    }

    // Add a new "Unsubscribed" column to the UserDiscussion table.
    public function Structure() {
        Gdn::Database()
            ->Structure()
            ->Table('UserDiscussion')
            ->Column('Unsubscribed', 'tinyint(1)', '0')
            ->Set();
    }

    public function Setup() {
        $this->Structure();
    }

}
