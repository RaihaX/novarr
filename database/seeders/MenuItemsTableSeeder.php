<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use TCG\Voyager\Models\Menu;
use TCG\Voyager\Models\MenuItem;

class MenuItemsTableSeeder extends Seeder
{
    /**
     * Auto generated seed file.
     *
     * @return void
     */
    public function run()
    {
        \Log::info('MenuItemsTableSeeder: Starting menu seeding');

        $menu = Menu::where('name', 'admin')->firstOrFail();

        // First, create or update the Tools parent menu item
        $toolsMenuItem = MenuItem::updateOrCreate(
            [
                'menu_id' => $menu->id,
                'title'   => __('voyager::seeders.menu_items.tools'),
            ],
            [
                'url'        => '',
                'target'     => '_self',
                'icon_class' => 'voyager-tools',
                'color'      => null,
                'parent_id'  => null,
                'order'      => 3,
            ]
        );

        // Define items that should be moved under Tools with their desired order
        $toolsChildItems = [
            ['route' => 'voyager.commands.index', 'title' => 'Commands', 'icon' => 'voyager-terminal', 'order' => 1],
            ['route' => 'voyager.logs.index', 'title' => 'Logs', 'icon' => 'voyager-file-text', 'order' => 2],
            ['route' => 'voyager.users.index', 'title' => __('voyager::seeders.menu_items.users'), 'icon' => 'voyager-person', 'order' => 3],
            ['route' => 'voyager.roles.index', 'title' => __('voyager::seeders.menu_items.roles'), 'icon' => 'voyager-lock', 'order' => 4],
            ['route' => 'voyager.media.index', 'title' => __('voyager::seeders.menu_items.media'), 'icon' => 'voyager-images', 'order' => 5],
            ['route' => 'voyager.menus.index', 'title' => __('voyager::seeders.menu_items.menu_builder'), 'icon' => 'voyager-list', 'order' => 6],
            ['route' => 'voyager.database.index', 'title' => __('voyager::seeders.menu_items.database'), 'icon' => 'voyager-data', 'order' => 7],
            ['route' => 'voyager.bread.index', 'title' => __('voyager::seeders.menu_items.bread'), 'icon' => 'voyager-bread', 'order' => 8],
            ['route' => 'voyager.settings.index', 'title' => __('voyager::seeders.menu_items.settings'), 'icon' => 'voyager-settings', 'order' => 9],
        ];

        // Items that should be hidden (deleted from menu)
        $itemsToHide = [
            'voyager.novel-chapters.index',
            'voyager.compass.index',
        ];

        // Delete items that should be hidden
        MenuItem::where('menu_id', $menu->id)
            ->whereIn('route', $itemsToHide)
            ->delete();

        // Also delete by title for items that might not have routes
        MenuItem::where('menu_id', $menu->id)
            ->whereIn('title', ['Novel Chapters', 'Compass'])
            ->delete();

        // Dashboard - top level, order 1 (always update to ensure correct position)
        MenuItem::updateOrCreate(
            [
                'menu_id' => $menu->id,
                'route'   => 'voyager.dashboard',
            ],
            [
                'title'      => __('voyager::seeders.menu_items.dashboard'),
                'url'        => '',
                'target'     => '_self',
                'icon_class' => 'voyager-boat',
                'color'      => null,
                'parent_id'  => null,
                'order'      => 1,
            ]
        );

        // Novels - top level, order 2 (always update to ensure correct position)
        MenuItem::updateOrCreate(
            [
                'menu_id' => $menu->id,
                'route'   => 'voyager.novels.index',
            ],
            [
                'title'      => 'Novels',
                'url'        => '',
                'target'     => '_self',
                'icon_class' => 'voyager-book',
                'color'      => null,
                'parent_id'  => null,
                'order'      => 2,
            ]
        );

        // Update or create all Tools child items - this ensures existing items are moved under Tools
        foreach ($toolsChildItems as $item) {
            MenuItem::updateOrCreate(
                [
                    'menu_id' => $menu->id,
                    'route'   => $item['route'],
                ],
                [
                    'title'      => $item['title'],
                    'url'        => '',
                    'target'     => '_self',
                    'icon_class' => $item['icon'],
                    'color'      => null,
                    'parent_id'  => $toolsMenuItem->id,
                    'order'      => $item['order'],
                ]
            );
        }

        // Find and relocate any existing top-level items that should be under Tools
        // This handles items that might exist with different titles (e.g., localized)
        $routesToMoveUnderTools = array_column($toolsChildItems, 'route');

        MenuItem::where('menu_id', $menu->id)
            ->whereIn('route', $routesToMoveUnderTools)
            ->whereNull('parent_id')
            ->update(['parent_id' => $toolsMenuItem->id]);

        // Also handle items by common titles that might be at top level
        $titlesToMoveUnderTools = ['Users', 'Roles', 'Media', 'Settings', 'Menu Builder', 'Database', 'BREAD'];

        MenuItem::where('menu_id', $menu->id)
            ->whereIn('title', $titlesToMoveUnderTools)
            ->where('parent_id', '!=', $toolsMenuItem->id)
            ->orWhere(function ($query) use ($menu, $titlesToMoveUnderTools) {
                $query->where('menu_id', $menu->id)
                    ->whereIn('title', $titlesToMoveUnderTools)
                    ->whereNull('parent_id');
            })
            ->update(['parent_id' => $toolsMenuItem->id]);

        \Log::info('MenuItemsTableSeeder: Completed successfully', [
            'tools_menu_id' => $toolsMenuItem->id,
            'items_created' => count($toolsChildItems),
        ]);
    }
}
