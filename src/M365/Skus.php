<?php
declare(strict_types=1);

namespace Align\M365;

/**
 * 2.6.0 Microsoft 365 subscriptions by their skuPartNumber: a readable name for the common ones (Graph only gives the
 * part number, e.g. SPB for Microsoft 365 Business Premium), which ones are free or trials (left out of Licensing by
 * default), and a category for Licensing. The MSP can rename any of them and change what's left out on the price
 * list (Integrations → Microsoft 365 (clients)); a part number not listed here shows as itself.
 *
 * Security assumptions: constants only. Part numbers come from Microsoft (remote data): callers cut them to the
 * column size and views escape them.
 */
final class Skus
{
    /** skuPartNumber => readable name. */
    public const NAMES = [
        'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'O365_BUSINESS_PREMIUM' => 'Microsoft 365 Business Standard',
        'SPB' => 'Microsoft 365 Business Premium',
        'O365_BUSINESS' => 'Microsoft 365 Apps for business',
        'SMB_BUSINESS' => 'Microsoft 365 Apps for business',
        'OFFICESUBSCRIPTION' => 'Microsoft 365 Apps for enterprise',
        'SPE_E3' => 'Microsoft 365 E3',
        'SPE_E5' => 'Microsoft 365 E5',
        'SPE_F1' => 'Microsoft 365 F3',
        'STANDARDPACK' => 'Office 365 E1',
        'ENTERPRISEPACK' => 'Office 365 E3',
        'ENTERPRISEPREMIUM' => 'Office 365 E5',
        'DESKLESSPACK' => 'Office 365 F3',
        'EXCHANGESTANDARD' => 'Exchange Online (Plan 1)',
        'EXCHANGEENTERPRISE' => 'Exchange Online (Plan 2)',
        'EXCHANGEARCHIVE_ADDON' => 'Exchange Online Archiving',
        'EXCHANGEDESKLESS' => 'Exchange Online Kiosk',
        'AAD_PREMIUM' => 'Microsoft Entra ID P1',
        'AAD_PREMIUM_P2' => 'Microsoft Entra ID P2',
        'EMS' => 'Enterprise Mobility + Security E3',
        'EMSPREMIUM' => 'Enterprise Mobility + Security E5',
        'INTUNE_A' => 'Microsoft Intune Plan 1',
        'ATP_ENTERPRISE' => 'Microsoft Defender for Office 365 (Plan 1)',
        'THREAT_INTELLIGENCE' => 'Microsoft Defender for Office 365 (Plan 2)',
        'DEFENDER_ENDPOINT_P1' => 'Microsoft Defender for Endpoint P1',
        'MDATP_XPLAT' => 'Microsoft Defender for Endpoint P2',
        'Microsoft_365_Copilot' => 'Microsoft 365 Copilot',
        'POWER_BI_PRO' => 'Power BI Pro',
        'PBI_PREMIUM_PER_USER' => 'Power BI Premium Per User',
        'POWER_BI_STANDARD' => 'Power BI (free)',
        'PROJECT_P1' => 'Project Plan 1',
        'PROJECTPROFESSIONAL' => 'Project Plan 3',
        'PROJECTPREMIUM' => 'Project Plan 5',
        'VISIOONLINE_PLAN1' => 'Visio Plan 1',
        'VISIOCLIENT' => 'Visio Plan 2',
        'MCOEV' => 'Microsoft Teams Phone Standard',
        'MCOPSTN1' => 'Microsoft Teams Domestic Calling Plan',
        'MCOPSTN2' => 'Microsoft Teams Domestic and International Calling Plan',
        'MCOMEETADV' => 'Microsoft 365 Audio Conferencing',
        'MCOCAP' => 'Microsoft Teams Shared Devices',
        'PHONESYSTEM_VIRTUALUSER' => 'Microsoft Teams Phone Resource Account',
        'Microsoft_Teams_Premium' => 'Microsoft Teams Premium',
        'Microsoft_Teams_Rooms_Pro' => 'Microsoft Teams Rooms Pro',
        'TEAMS_EXPLORATORY' => 'Microsoft Teams Exploratory',
        'Microsoft_Teams_Exploratory_Dept' => 'Microsoft Teams Exploratory',
        'FLOW_FREE' => 'Power Automate Free',
        'FLOW_PER_USER' => 'Power Automate per user',
        'POWERAUTOMATE_ATTENDED_RPA' => 'Power Automate Premium',
        'POWERAPPS_VIRAL' => 'Power Apps Plan 2 Trial',
        'POWERAPPS_DEV' => 'Power Apps for Developer',
        'Win10_VDA_E3' => 'Windows Enterprise E3',
        'WIN10_VDA_E5' => 'Windows Enterprise E5',
        'WINDOWS_STORE' => 'Windows Store for Business',
        'STREAM' => 'Microsoft Stream Trial',
        'RIGHTSMANAGEMENT_ADHOC' => 'Rights Management Adhoc',
        'CCIBOTS_PRIVPREV_VIRAL' => 'Power Virtual Agents Viral Trial',
        'FORMS_PRO' => 'Dynamics 365 Customer Voice Trial',
        'MICROSOFT_BUSINESS_CENTER' => 'Microsoft Business Center',
    ];

    /** Free, trial and resource subscriptions: left out of Licensing until the MSP says otherwise on the price list. */
    public const FREE = [
        'POWER_BI_STANDARD', 'TEAMS_EXPLORATORY', 'Microsoft_Teams_Exploratory_Dept', 'FLOW_FREE', 'POWERAPPS_VIRAL', 'POWERAPPS_DEV',
        'WINDOWS_STORE', 'STREAM', 'RIGHTSMANAGEMENT_ADHOC', 'CCIBOTS_PRIVPREV_VIRAL', 'FORMS_PRO', 'MICROSOFT_BUSINESS_CENTER', 'PHONESYSTEM_VIRTUALUSER',
    ];

    /** A readable name: the known one (matched ignoring case: Microsoft isn't consistent), else the part number with spaces for underscores. */
    public static function name(string $part): string
    {
        static $lower = null;
        $lower ??= array_change_key_case(self::NAMES, CASE_LOWER);
        return $lower[strtolower($part)] ?? trim(str_replace('_', ' ', $part));
    }

    /** Whether a part number is free or a trial (left out of Licensing by default), ignoring case. */
    public static function free(string $part): bool
    {
        return in_array(strtolower($part), array_map('strtolower', self::FREE), true);
    }

    /** The Licensing category: identity and device security add-ons are Security, the rest by name (Productivity / M365 mostly). */
    public static function category(string $part, string $name): string
    {
        if (preg_match('/^(AAD_PREMIUM|EMS|ATP_|THREAT_INTELLIGENCE|DEFENDER_|MDATP)/i', $part)) {
            return 'security';
        }
        $c = \Align\Licensing\Licenses::guessCategory($name);
        return $c === 'other' ? 'productivity' : $c;
    }
}
