<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Lifecycle\AssetTypes;
use Align\Lifecycle\Lifecycle;

/**
 * 2.25.0 An admin's own asset types (Settings → Planning & lifecycle): add, change, remove. See AssetTypes.
 *
 * Security assumptions: admins only (Auth::requireRole first in every action); POSTs are CSRF-checked by the router.
 * The id comes from the route and is looked up before anything changes; values go through AssetTypes::clean and
 * AssetTypes::problem (fixed categories and icons). Every change is audited. Redirects go to a fixed path.
 */
final class AssetTypeController
{
    private const BACK = '/settings/planning#asset-types';

    /** The posted values: [name, class, icon, virtual]. */
    private static function posted(): array
    {
        return [AssetTypes::clean(post('name')), post('class'), post('icon') ?: 'fa-tag', post('is_virtual') === '1'];
    }

    /** Adds a type, and sorts Unassigned PSA assets whose type has exactly that name into it. */
    public static function create(): void
    {
        $u = Auth::requireRole('admin');
        [$name, $class, $icon, $virtual] = self::posted();
        if ($err = AssetTypes::problem($name, $class, $icon)) {
            flash('error', $err);
            redirect(self::BACK);
        }
        try {
            [, $sorted] = AssetTypes::create($name, $class, $icon, $virtual, (int) $u['id']);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            flash('error', "There's already a type called \"$name\"."); // added by someone else just now
            redirect(self::BACK);
        }
        Audit::log('asset_type.create', $name . ' (' . Lifecycle::CLASSES[$class] . ($virtual ? ', virtual' : '') . ')' . ($sorted ? ", $sorted device" . ($sorted === 1 ? '' : 's') . ' sorted' : ''));
        flash('success', "Added the asset type \"$name\"." . ($sorted ? ' ' . ($sorted === 1 ? '1 unassigned device' : "$sorted unassigned devices") . " with that type in " . psa_name() . ' now use it.' : ''));
        redirect(self::BACK);
    }

    /** The custom type $id, or back to the page with a message. */
    private static function load(int $id): array
    {
        $row = DB::one('SELECT * FROM device_types WHERE id = ?', [$id]);
        if (!$row) {
            flash('error', 'That asset type no longer exists.');
            redirect(self::BACK);
        }
        return $row;
    }

    /** Changes a type; a new name is given to every device that had the old one. */
    public static function update(int $id): void
    {
        Auth::requireRole('admin');
        $row = self::load($id);
        [$name, $class, $icon, $virtual] = self::posted();
        if ($err = AssetTypes::problem($name, $class, $icon, $id)) {
            flash('error', $err);
            redirect(self::BACK);
        }
        try {
            $n = AssetTypes::update($row, $name, $class, $icon, $virtual);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            flash('error', "There's already a type called \"$name\".");
            redirect(self::BACK);
        }
        Audit::log('asset_type.update', ($row['name'] !== $name ? "{$row['name']} → " : '') . $name . ' (' . Lifecycle::CLASSES[$class] . ($virtual ? ', virtual' : '') . ')');
        flash('success', "Saved \"$name\"." . ($n ? " Renamed on $n device" . ($n === 1 ? '' : 's') . '.' : ''));
        redirect(self::BACK);
    }

    /** Removes a type: devices set to it by hand go back to their synced type, PSA assets of that type to Unassigned. */
    public static function delete(int $id): void
    {
        Auth::requireRole('admin');
        $row = self::load($id);
        $n = AssetTypes::delete($row);
        Audit::log('asset_type.delete', $row['name'] . ($n ? " ($n device" . ($n === 1 ? '' : 's') . ' changed)' : ''));
        flash('success', "Removed \"{$row['name']}\"." . ($n ? " $n device" . ($n === 1 ? '' : 's') . ' went back to their own or Unassigned type.' : ''));
        redirect(self::BACK);
    }
}
