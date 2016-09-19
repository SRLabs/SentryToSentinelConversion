# Sentry to Sentinel Migration

[![Build Status](https://travis-ci.org/SRLabs/SentryToSentinelConversion.svg?branch=master)](https://travis-ci.org/SRLabs/SentryToSentinelConversion)

This is a sandbox experiment for working out a migration to convert a [Cartalyst/Sentry](https://cartalyst.com/manual/sentry/2.1) schema to a schema that works with [Cartalyst/Sentinel](https://cartalyst.com/manual/sentinel/2.0).

These are the files you are most likely interested in: 

- [add_sentinel_schema migration](https://github.com/SRLabs/SentryToSentinelConversion/blob/master/database/migrations/2016_09_19_000103_add_sentinel_schema.php)
- [remove_sentry_schema migration](https://github.com/SRLabs/SentryToSentinelConversion/blob/master/database/migrations/2016_09_19_000113_remove_sentry_schema.php)

### NB
- There is a migration "down" path that attempts to restore the original Sentry DB schema.  However, certain types of data are lost in the upgrade and cannot be restored via the "down" path.  Make sure you make a backup before attempting this conversion. 
- These migrations are provided as is.  They worked for me, but I am not guaranteeing that they will work for you.  You should not run any migration against production data unless you understand exactly how it works and what it is doing. 
