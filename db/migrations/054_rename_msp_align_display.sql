-- 2.2.2: the app's name reads "MSP Align" (was "MSP-ALIGN"), matching the new logo. A branding name still set to the
-- old default (saving Settings -> Branding stored the name shown in its form) is cleared, so the new default name and
-- the built-in logo show; a custom name is left alone.
UPDATE settings SET value = '' WHERE name = 'brand_name' AND value = 'MSP-ALIGN';
