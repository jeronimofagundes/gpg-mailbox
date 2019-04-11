<?php
/**
 * Encrypts all e-mails in a mail account using GPG.
 * @author Jeronimo Fagundes <jeronimo@jeronimofagund.es>
 */
error_reporting(E_ALL);
require_once("config.php");

$gpgid = $config['gpgid'];

$imapSpec = "{{$config['host']}:{$config['port']}{$config['flags']}}";
if (false === ($imapConnection = imap_open($imapSpec, $config['username'], $config['password'], 0, 0, array("DISABLE_AUTHENTICATOR" => "GSSAPI")))) {
	echo "Error connecting to {$imapSpec}" . PHP_EOL;
	die(1);
}

$mailboxes = imap_list($imapConnection, $imapSpec, "*");
if (false === $mailboxes || count($mailboxes) == 0) {
    echo "Error reading mailboxes" . PHP_EOL;
    die(1);
}
if (!imap_close($imapConnection)) {
    echo "Failed closing an IMAP connection" . PHP_EOL;
    die(1);
}

foreach ($mailboxes as $mailboxSpec) {
	if (false === ($mailboxConnection = imap_open($mailboxSpec, $config['username'], $config['password'], 0, 0, array("DISABLE_AUTHENTICATOR" => "GSSAPI")))) {
		echo "Error connecting to {$mailboxSpec}" . PHP_EOL;
		die(1);
	}

	echo "================================" . PHP_EOL . $mailboxSpec . PHP_EOL;
	$allIds = imap_sort($mailboxConnection, SORTDATE, 1, SE_NOPREFETCH | SE_UID);
    $encryptedIds = imap_search($mailboxConnection, "BODY \"-----BEGIN PGP MESSAGE-----\"", SE_UID);

    if ($encryptedIds === false) {
        $encryptedIds = array();
    }
    $idsToEncrypt = array_diff($allIds, $encryptedIds);


    if (empty($idsToEncrypt)) {
        continue;
    }

    foreach ($idsToEncrypt as $id) {
        while(!imap_ping($mailboxConnection)) {
            imap_reopen($mailboxConnection, $mailboxSpec); // Ignores the fail case, as it will test the ping again.
        }
		$headers = imap_fetchheader($mailboxConnection, $id, FT_UID);
		$body = imap_body($mailboxConnection, $id, FT_UID);

		if (stripos($body, "-----BEGIN PGP MESSAGE-----") !== false) {
            echo "Skipping message, it looks already encrypted" . PHP_EOL;
            continue;
        }

		$msgNo = imap_msgno($mailboxConnection, $id);
		$headerInfo = imap_headerinfo($mailboxConnection, $msgNo);
		if ($headerInfo === false) {
            echo "Skipping message, error parsing headers" . PHP_EOL;
            continue;
        }
		$subject = $headerInfo->subject;

		if (stripos($subject, "Retrieval using the IMAP4 protocol failed") !== false) {
            echo "Skipping folder, it looks like an exchange calendar" . PHP_EOL;
            imap_close($mailboxConnection);
            continue 2;
        }

		$originalPath = tempnam(".", "imap_");

		if (false === $originalPath) {
		    echo "Could not create temporary file" . PHP_EOL;
		    die(1);
        }

		$raw = $headers . "\r\n" . $body;

		if (false === file_put_contents($originalPath,$raw)) {
		    echo "Could not write to temporary file" . PHP_EOL;
		    die(1);
        }

		$execOutput = array();
		exec("gpgit.pl --skip-ms-bug {$gpgid} < {$originalPath}", $execOutput, $execRet);
        unlink($originalPath);

		if ($execRet != 0) {
		    echo "Error running gpgit.pl" . PHP_EOL;
		    die;
        }

		$raw = implode("\r\n", $execOutput);
		$raw = str_replace("\r\n", "\n", $raw);
		$raw = str_replace("\n", "\r\n", $raw);

		if (stripos($raw, "-----BEGIN PGP MESSAGE-----") === false) {
            echo "Skipping message \"{$subject}\", error converting" . PHP_EOL;
            continue;
        }

		$res = imap_append($mailboxConnection, $mailboxSpec,$raw);

		if (!$res) {
			var_dump(imap_errors());
			continue;
		}

		imap_delete($mailboxConnection, $id, FT_UID);
		imap_expunge($mailboxConnection);
		echo "Converted \"{$subject}\"! Original deleted and expunged!" . PHP_EOL;
	}
	imap_close($mailboxConnection);
}

