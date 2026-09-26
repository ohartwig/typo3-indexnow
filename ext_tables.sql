# SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
# SPDX-License-Identifier: MIT
#
# One row per page and language waiting to be announced. The unique key is the
# deduplication: saving a page twenty times before the next run queues it once.
CREATE TABLE tx_kohindexnow_queue (
	uid int(11) unsigned NOT NULL auto_increment,
	page int(11) unsigned DEFAULT '0' NOT NULL,
	language int(11) DEFAULT '0' NOT NULL,
	reason varchar(16) DEFAULT '' NOT NULL,
	attempts int(11) unsigned DEFAULT '0' NOT NULL,
	not_before int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY page_language (page, language),
	KEY due (not_before)
);
