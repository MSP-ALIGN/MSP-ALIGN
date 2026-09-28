-- 1.30.1: the project moved to github.com/MSP-ALIGN/MSP-ALIGN. A saved "Source code link" that still
-- points to the old repository follows it; any other link is left as it is.
UPDATE settings SET value = 'https://github.com/MSP-ALIGN/MSP-ALIGN'
WHERE name = 'source_url'
  AND LOWER(TRIM(TRAILING '/' FROM value)) IN ('https://github.com/mountaineerit/mountaineer-align', 'https://github.com/mountaineerit/mountaineer-align.git');
