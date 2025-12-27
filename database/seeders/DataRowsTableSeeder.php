<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use TCG\Voyager\Models\DataRow;
use TCG\Voyager\Models\DataType;

class DataRowsTableSeeder extends Seeder
{
    /**
     * Auto generated seed file.
     */
    public function run()
    {
        $userDataType = DataType::where('slug', 'users')->firstOrFail();
        $menuDataType = DataType::where('slug', 'menus')->firstOrFail();
        $roleDataType = DataType::where('slug', 'roles')->firstOrFail();

        $dataRow = $this->dataRow($userDataType, 'id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => __('voyager::seeders.data_rows.id'),
                'required'     => 1,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 1,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'name');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => __('voyager::seeders.data_rows.name'),
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 2,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'email');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => __('voyager::seeders.data_rows.email'),
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 3,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'password');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'password',
                'display_name' => __('voyager::seeders.data_rows.password'),
                'required'     => 1,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 0,
                'order'        => 4,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'remember_token');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => __('voyager::seeders.data_rows.remember_token'),
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 5,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'created_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => __('voyager::seeders.data_rows.created_at'),
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 6,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'updated_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => __('voyager::seeders.data_rows.updated_at'),
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 7,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'avatar');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'image',
                'display_name' => __('voyager::seeders.data_rows.avatar'),
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 8,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'user_belongsto_role_relationship');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'relationship',
                'display_name' => __('voyager::seeders.data_rows.role'),
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 0,
                'details'      => [
                    'model'       => 'TCG\\Voyager\\Models\\Role',
                    'table'       => 'roles',
                    'type'        => 'belongsTo',
                    'column'      => 'role_id',
                    'key'         => 'id',
                    'label'       => 'display_name',
                    'pivot_table' => 'roles',
                    'pivot'       => 0,
                ],
                'order'        => 10,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'user_belongstomany_role_relationship');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'relationship',
                'display_name' => __('voyager::seeders.data_rows.roles'),
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 0,
                'details'      => [
                    'model'       => 'TCG\\Voyager\\Models\\Role',
                    'table'       => 'roles',
                    'type'        => 'belongsToMany',
                    'column'      => 'id',
                    'key'         => 'id',
                    'label'       => 'display_name',
                    'pivot_table' => 'user_roles',
                    'pivot'       => '1',
                    'taggable'    => '0',
                ],
                'order'        => 11,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'settings');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'hidden',
                'display_name' => 'Settings',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 12,
            ])->save();
        }

        $dataRow = $this->dataRow($menuDataType, 'id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => __('voyager::seeders.data_rows.id'),
                'required'     => 1,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 1,
            ])->save();
        }

        $dataRow = $this->dataRow($menuDataType, 'name');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => __('voyager::seeders.data_rows.name'),
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 2,
            ])->save();
        }

        $dataRow = $this->dataRow($menuDataType, 'created_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => __('voyager::seeders.data_rows.created_at'),
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 3,
            ])->save();
        }

        $dataRow = $this->dataRow($menuDataType, 'updated_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => __('voyager::seeders.data_rows.updated_at'),
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 4,
            ])->save();
        }

        $dataRow = $this->dataRow($roleDataType, 'id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => __('voyager::seeders.data_rows.id'),
                'required'     => 1,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 1,
            ])->save();
        }

        $dataRow = $this->dataRow($roleDataType, 'name');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => __('voyager::seeders.data_rows.name'),
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 2,
            ])->save();
        }

        $dataRow = $this->dataRow($roleDataType, 'created_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => __('voyager::seeders.data_rows.created_at'),
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 3,
            ])->save();
        }

        $dataRow = $this->dataRow($roleDataType, 'updated_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => __('voyager::seeders.data_rows.updated_at'),
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 4,
            ])->save();
        }

        $dataRow = $this->dataRow($roleDataType, 'display_name');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => __('voyager::seeders.data_rows.display_name'),
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 5,
            ])->save();
        }

        $dataRow = $this->dataRow($userDataType, 'role_id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => __('voyager::seeders.data_rows.role'),
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 9,
            ])->save();
        }

        // Novel DataRows
        $this->seedNovelDataRows();

        // Novel Chapter DataRows
        $this->seedNovelChapterDataRows();
    }

    /**
     * Seed Novel DataRows.
     */
    protected function seedNovelDataRows()
    {
        $novelDataType = DataType::where('slug', 'novels')->firstOrFail();

        $dataRow = $this->dataRow($novelDataType, 'id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'ID',
                'required'     => 1,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 1,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'name');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Name',
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 2,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'author');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Author',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 3,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'description');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text_area',
                'display_name' => 'Description',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 4,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'translator');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Translator',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 5,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'translator_url');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Translator URL',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 6,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'chapter_url');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Chapter URL',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 7,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'status');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'checkbox',
                'display_name' => 'Status',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'details'      => [
                    'on'  => 'Active',
                    'off' => 'Inactive',
                ],
                'order'        => 8,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'group_id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Group ID',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 9,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'novel_belongsto_group_relationship');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'relationship',
                'display_name' => 'Group',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 0,
                'details'      => [
                    'model'       => 'App\\Group',
                    'table'       => 'groups',
                    'type'        => 'belongsTo',
                    'column'      => 'group_id',
                    'key'         => 'id',
                    'label'       => 'name',
                    'pivot_table' => 'groups',
                    'pivot'       => 0,
                ],
                'order'        => 10,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'language_id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Language ID',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 11,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'novel_belongsto_language_relationship');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'relationship',
                'display_name' => 'Language',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 0,
                'details'      => [
                    'model'       => 'App\\Language',
                    'table'       => 'languages',
                    'type'        => 'belongsTo',
                    'column'      => 'language_id',
                    'key'         => 'id',
                    'label'       => 'name',
                    'pivot_table' => 'languages',
                    'pivot'       => 0,
                ],
                'order'        => 12,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'json');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'code_editor',
                'display_name' => 'JSON',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'details'      => [
                    'language' => 'json',
                ],
                'order'        => 13,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'unique_id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Unique ID',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 14,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'current_chapters');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Current Chapters',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 15,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'chapters_not_downloaded');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Chapters Not Downloaded',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 16,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'duplicate_chapters');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Duplicate Chapters',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 17,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'no_of_chapters');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Number of Chapters',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 18,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'progress');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Progress',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 19,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'missing_chapters');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Missing Chapters',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 20,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'alternative_url');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Alternative URL',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 21,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'novel_hasmany_chapter_relationship');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'relationship',
                'display_name' => 'Chapters',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => [
                    'model'       => 'App\\NovelChapter',
                    'table'       => 'novel_chapters',
                    'type'        => 'hasMany',
                    'column'      => 'novel_id',
                    'key'         => 'id',
                    'label'       => 'label',
                    'pivot_table' => 'novel_chapters',
                    'pivot'       => 0,
                ],
                'order'        => 22,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'created_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => 'Created At',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 23,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'updated_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => 'Updated At',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 24,
            ])->save();
        }

        $dataRow = $this->dataRow($novelDataType, 'deleted_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => 'Deleted At',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 25,
            ])->save();
        }
    }

    /**
     * Seed Novel Chapter DataRows.
     */
    protected function seedNovelChapterDataRows()
    {
        $novelChapterDataType = DataType::where('slug', 'novel-chapters')->firstOrFail();

        $dataRow = $this->dataRow($novelChapterDataType, 'id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'ID',
                'required'     => 1,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 1,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'novel_id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Novel ID',
                'required'     => 1,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 2,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'novelchapter_belongsto_novel_relationship');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'relationship',
                'display_name' => 'Novel',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 0,
                'details'      => [
                    'model'       => 'App\\Novel',
                    'table'       => 'novels',
                    'type'        => 'belongsTo',
                    'column'      => 'novel_id',
                    'key'         => 'id',
                    'label'       => 'name',
                    'pivot_table' => 'novels',
                    'pivot'       => 0,
                ],
                'order'        => 3,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'chapter');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Chapter',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 4,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'label');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Label',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 5,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'description');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'rich_text_box',
                'display_name' => 'Description',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 6,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'url');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'URL',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 7,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'book');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'number',
                'display_name' => 'Book',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 8,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'unique_id');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'Unique ID',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 9,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'status');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'checkbox',
                'display_name' => 'Status',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'details'      => [
                    'on'  => 'Downloaded',
                    'off' => 'Pending',
                ],
                'order'        => 10,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'double_chapter');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'checkbox',
                'display_name' => 'Double Chapter',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'details'      => [
                    'on'  => 'Yes',
                    'off' => 'No',
                ],
                'order'        => 11,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'blacklist');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'checkbox',
                'display_name' => 'Blacklist',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'details'      => [
                    'on'  => 'Blacklisted',
                    'off' => 'Normal',
                ],
                'order'        => 12,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'download_date');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => 'Download Date',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 13,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'html_file');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'text',
                'display_name' => 'HTML File',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'order'        => 14,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'created_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => 'Created At',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 15,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'updated_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => 'Updated At',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 16,
            ])->save();
        }

        $dataRow = $this->dataRow($novelChapterDataType, 'deleted_at');
        if (!$dataRow->exists) {
            $dataRow->fill([
                'type'         => 'timestamp',
                'display_name' => 'Deleted At',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'order'        => 17,
            ])->save();
        }
    }

    /**
     * [dataRow description].
     *
     * @param [type] $type  [description]
     * @param [type] $field [description]
     *
     * @return [type] [description]
     */
    protected function dataRow($type, $field)
    {
        return DataRow::firstOrNew([
            'data_type_id' => $type->id,
            'field'        => $field,
        ]);
    }
}
