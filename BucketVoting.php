<?php
/**
 * Bucket Voting extension - this extension allows users to assign votes
 * across a number of candidates.
 *
 * To include this extension, add the following to LocalSettings.php:
 * require_once("$IP/extensions/BucketVoting/BucketVoting.php");
 *
 * @ingroup Extensions
 * @author Robin Sheat (Catalyst .Net Ltd.) <robin@catalyst.net.nz>
 * @version 1.0
 * @license (unknown)
 */

if (!defined('MEDIAWIKI')) {
    echo("This is an extension to the MediaWiki package and cannot be run standalone.\n" );
    die(-1);
}

// ------ All the setup functions are up here ------

# Credits that show up on Special:Version
$wgExtensionCredits['parserhook'][] = array(
    'path'          => __FILE__,
    'name'          => 'Bucket Voting',
    'version'       => '1.0',
    'author'        => 'Robin Sheat (Catalyst .Net Ltd.)',
    'description'   => 'This extension allows votes to be distributed proprtionally amongst a number of candidates',
    'license'       => 'GPL'
);

# Allows the table to be defined on update - may not be useful in this case,
# but shouldn't hurt to have it here.
$wgHooks['LoadExtensionSchemaUpdates'][] = 'wfBuckVoting_DatabaseSetup';

# This aids support for older MW instances
if ( defined( 'MW_SUPPORTS_PARSERFIRSTCALLINIT' ) ) {
        $wgHooks['ParserFirstCallInit'][] = 'wfBucketVoting_Setup';
} else {
        $wgExtensionFunctions[] = 'wfBucketVoting_Setup';
}

function wfBucketVoting_Setup() {
    global $wgParser;
    // This lists all the tag->function mappings we need
    $wgParser->setHook('vote-start',    'wfBucketVoting_VoteStart');
    $wgParser->setHook('vote-end',      'wfBucketVoting_VoteEnd');
    $wgParser->setHook('vote-number',   'wfBucketVoting_VoteNumber');
    $wgParser->setHook('vote',          'wfBucketVoting_Vote');
    $wgParser->setHook('vote-admin-summary', 'wfBucketVoting_VoteAdminSummary');
    return true;
}

function wfBucketVoting_DatabaseSetup() {
    global $wgExtNewTables;
    $wgExtNewTables[] = array(
        'bucketvotes',
        dirname(__FILE__) . '/BucketVoting.sql'
    );
    return true;
}

// ------ Down here are the functions that do the actual work ------

// (the docs say this stuff should be in a file on its own, but MW spat the dummy
// when I tried to do that, so I moved it here.)

# This holds information that needs to be carried between the methods for
# the bucket voting. Should be inited each time a form is begun.
$wgBucketVoting_data = null;

# The vote-start function has two main roles: 
# * It starts a form that will be used by the other voting elements when the 
#   time comes to submit the votes, and 
# * it checks to see if a POST has occurred and processes the values, saving 
#   the new voting details. 
# Args: locked: true/false - are users prevented from chaging their 
#               votes? (def: false)
#       numvotes: the number of votes that will be distributed (def: 10)
function wfBucketVoting_VoteStart($input, $args, $parser) {
    global $wgTitle, $wgBucketVoting_data;
    $wgBucketVoting_data = array();
    if (isset($args['locked'])) {
        $wgBucketVoting_data['locked'] = $args['locked'] == 'true';
    } else {
        $wgBucketVoting_data['locked'] = false;
    }
    $numVotes = intval($args['numvotes']);
    if ($numVotes == 0) { // Most likely it's not a number
        $numVotes = 10;
    }
    $wgBucketVoting_data['numvotes'] = $numVotes;
    $title = $wgTitle->getText();
    $wgBucketVoting_data['votekey'] = $title;

    $output = '';
    // It's now time to catch the POST submission, if any, and do something
    // about it.
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['voteaction'])) {
        $action = $_POST['voteaction'];
        if ($action == 'vote') {
            $output .= wfBucketVoting_VoteHandlePost($_POST);
        } elseif ($action == 'admin') {
            $output .= wfBucketVoting_AdminHandlePost($_POST);
        }
    }

    if ($wgBucketVoting_data['locked']) {
        $output .= '';
    } else {
        $output .= '<form name="'.$title.'" method="post">';
    }
    return $output;
}

# The vote-end function closes off the form and provides a submit button.
function wfBucketVoting_VoteEnd($input, $args, $parser) {
    global $wgBucketVoting_data;
    if ($wgBucketVoting_data == null) {
        return wfBucketVoting_FormatError('Calling vote-end without a previous vote-start is not allowed');
    }
    if (isset($args['text'])) {
        $button_text = htmlspecialchars($args['text']);
    } else {
        $button_text = htmlspecialchars($input != "" ? $input : "Submit Votes");
    }
    $output = '';
    if (!$wgBucketVoting_data['locked']) {
        $output .= '<input type="hidden" name="voteaction" value="vote" />'."\n";
        $output .= '<input type="hidden" name="_votekey" value="'.$wgBucketVoting_data['votekey'].'" />'."\n";
        $output .= '<input type="submit" value="'.$button_text.'" />'."\n";
        $output .= '</form>'."\n";
    }
    return $output;
}

# This presents the number of votes that are available to the user to distribute
function wfBucketVoting_VoteNumber($input, $args, $parser) {
    global $wgBucketVoting_data;
    if ($wgBucketVoting_data == null) {
        return wfBucketVoting_FormatError('Calling vote-number without a previous vote-start is not allowed');
    }
    return $wgBucketVoting_data['numvotes'];
}

# This is a box that the user can vote in.
# Args: key - an admin-only description of this vote field
function wfBucketVoting_Vote($input, $args, $parser) {
    global $wgBucketVoting_data, $wgUser;
    $parser->disableCache();
    if ($wgBucketVoting_data == null) {
        return wfBucketVoting_FormatError('Calling vote without a previous vote-start is not allowed');
    }

    $key = $args['key'];
    if ($key == null || $key == '') {
        trigger_error(htmlspecialchars("A <vote> tag must have a key attribute that uniquely describes it, e.g. '<vote key=\"enh1234\" />"), E_USER_WARNING);
        return;
    }
    $userid = $wgUser->getId();
    $dbr = wfGetDB(DB_SLAVE); // We're only going to be reading here
    // Find any existing vote by this user on this vote item
    $currVoteData = $dbr->select('bucketvotes', 'vote', 
        array(
            "userid" => $userid, 
            "votekey" => $wgBucketVoting_data['votekey'],
            "voteitemkey" => $key
        ),
        'wfBucketVoting_Vote'
    );
    $output = '';
    $currVoteRow = $dbr->fetchRow($currVoteData);
    if (!$currVoteRow) {
        $currVote = 0;
    } else {
        $currVote = $currVoteRow['vote'];
    }
    if ($wgBucketVoting_data['locked']) {
        $output .= "<span class=\"lockedvote\">$currVote</span>";
    } else {
        # Base-64 encoding because PHP will turn ' ' and '.' to '_' on POSTs
        # which causes confusion.
        $output .= '<input type="text" size="3" name="item_'.htmlspecialchars(base64_encode($key)).'" value="'.$currVote.'" />';
    }
    return $output;
}

# Shows a summary of the votes recorded by all users. This only does anything
# if the user is an admin.
function wfBucketVoting_VoteAdminSummary($input, $args, $parser) {
    global $wgBucketVoting_data, $wgUser, $wgBucketVoting_admingroup;
    $parser->disableCache();
    if ($wgBucketVoting_data == null) {
        return wfBucketVoting_FormatError('Calling vote-admin-summary without a previous vote-start is not allowed');
    }

    if (!wfBucketVoting_IsAdmin())
        return;
    
    // Load the vote info from the db
    // Something like:
    // SELECT voteitemkey,SUM(vote) AS votes FROM bucketvotes WHERE votekey=<key> GROUP BY voteitemkey ORDER BY votes DESC;
    $dbr = wfGetDB(DB_SLAVE);
    $dbRows = $dbr->select('bucketvotes', 
        array(
            'voteitemkey',
            'sum(vote) AS votes'), 
        array(
            'votekey' => $wgBucketVoting_data['votekey'],
        ),
        'wfBucketVoting_VoteAdminSummary',
        array(
            'GROUP BY' => 'voteitemkey',
            'ORDER BY' => 'votes DESC'
        )
    );
    $output = '';
    $output .= '<form method="POST" name="admin_functions_'.htmlspecialchars($wgBucketVoting_data['votekey'])."\" />\n";
    $output .= "<table border=\"1\"><tr><th>Item Key</th><th>Total Votes</th><th>Delete</th></tr>\n";
    while ($row = $dbr->fetchRow($dbRows)) {
        $output .= '<tr><td>'.htmlspecialchars($row['voteitemkey']).'</td>';
        $output .= '<td align="right">'.htmlspecialchars($row['votes']).'</td>';
        $output .= '<td align="center"><input type="checkbox" name="delete_'.htmlspecialchars(base64_encode($row['voteitemkey'])).'" /></td>';
        $output .= "</tr>\n";
    }
    $output .= "</table><input name=\"voteaction\" type=\"hidden\" value=\"admin\" /><input type=\"submit\" value=\"Update\" /></form>\n";
    return $output;
}

# This processes any 'post' variables that were included with this request. It
# will sanity-check the values (as much as it can) and insert them into the
# database.
# Returns: a string, if there is a message to display, empty string otherwise.
function wfBucketVoting_VoteHandlePost($post_data) {
    global $wgBucketVoting_data, $wgUser;

    if ($wgBucketVoting_data['locked'])
        return;
    
    $votekey = $post_data['_votekey'];
    if ($votekey == null || $votekey == '')
        return;
    if ($votekey != $wgBucketVoting_data['votekey']) {
        trigger_error("Attempted to set the vote parameters for a page that isn't this one", E_USER_ERROR);
        return;
    }
    $numVotes = $wgBucketVoting_data['numvotes'];
    $rawVotes = array();
    $sumVotes = 0;
    $userid = $wgUser->getId();
    if ($userid == 0) {
        return wfBucketVoting_FormatError('Only logged-in users can vote.');
    }
    $dbw = wfGetDB(DB_MASTER);
    foreach($post_data as $p_key => $p_value) {
        if (substr($p_key,0,5) != 'item_') {
            continue;
        }
        $itemkey = base64_decode(substr($p_key,5));
        if ($p_value == null || $p_value == "")
            $p_value = 0;
        if (!is_numeric($p_value) || $p_value < 0) {
            return wfBucketVoting_FormatError('Each vote should be a number greater than zero');
        }
        $rawVotes[$itemkey] = $p_value;
        $sumVotes += $p_value;
    }

    if ($sumVotes == 0) {
        $dbw->delete('bucketvotes', 
            array(
                'userid' => $userid,
                'votekey' => $votekey
            )
        );
        return;
        #return wfBucketVoting_FormatError('All votes set to zero');
    }

    // Scale votes to match numvotes
    $scale = $sumVotes / $numVotes;
    foreach($rawVotes as $itemkey => $value) {
        $scaledValue = floor($value / $scale);
        // Because the mediawiki DB stuff doesn't give a 'rows updated' count,
        // we have to check first.
        $dbw->begin();
        $matchArgs = array(
            'userid' => $userid,
            'votekey' => $votekey,
            'voteitemkey' => $itemkey
        );
        $res = $dbw->select('bucketvotes',
            'vote', $matchArgs, 'wfBucketVoting_DoPost');
        if (!$dbw->fetchRow($res)) {
            $dbw->insert('bucketvotes',
                array('userid' =>   $userid,
                    'votekey' =>    $votekey,
                    'voteitemkey'=> $itemkey,
                    'vote' =>       $scaledValue
                ),
                'wfBucketVoting_DoPost'
            );
        } else {
            $dbw->update('bucketvotes',
                array('vote' => $scaledValue),
                array(
                    'userid' => $userid,
                    'votekey' => $votekey,
                    'voteitemkey' => $itemkey
                ),
                'wfBucketVoting_DoPost'
            );
        }
        $dbw->commit();
    }
}

# This handles any admin functions that were requested in the POST
function wfBucketVoting_AdminHandlePost($post_data) {
    if (!wfBucketVoting_IsAdmin()) {
        trigger_error("Only admins should be making this request", E_USER_ERROR);
        return;
    }
    $output = '';
    foreach ($post_data as $key => $value) {
        if (substr($key,0,7) == 'delete_') {
            $output .= wfBucketVoting_AdminDeleteVotes(base64_decode(substr($key,7)));
        }
    }
    return $output;
}

# Deletes all the votes associated with the key
function wfBucketVoting_AdminDeleteVotes($voteitemkey) {    
    global $wgBucketVoting_data;

    $dbw = wfGetDB(DB_MASTER);
    $votekey = $wgBucketVoting_data['votekey'];
    $dbw->delete('bucketvotes',
        array(
            'votekey' => $votekey,
            'voteitemkey' => $voteitemkey
        ),
        'wfBucketVoting_AdminDeleteVotes');
}

# Returns the supplied text formatted like it would be if it were an error
function wfBucketVoting_FormatError($text) {
    return "<span class=\"error\">$text</span>";
}

# Determines if the user is a member of the group that can manage votes
function wfBucketVoting_IsAdmin() {
    global $wgUser;

    // Is the user in the right group?
    $adminGroup = isset($wgBucketVoting_admingroup) ? $wgBucketVoting_admingroup : "sysop";
    $groups = $wgUser->getGroups();
    $validUser = false;
    foreach ($groups as $group) {
        if ($group == $adminGroup) {
            return true;
        }
    }
    return false;
}
