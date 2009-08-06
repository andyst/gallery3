<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Organize_Controller extends Controller {
  private static $_MICRO_THUMB_SIZE = 90;
  private static $_MICRO_THUMB_PADDING = 10;

  function index($item_id) {
    $item = ORM::factory("item", $item_id);
    $root = $item->id == 1 ? $item : ORM::factory("item", 1);
    access::required("view", $item);
    access::required("edit", $item);

    $v = new View("organize_dialog.html");
    $v->title = $item->title;
    $parents = array();
    foreach ($item->parents() as $parent) {
      $parents[$parent->id] = 1;
    }
    $parents[$item->id] = 1;

    $v->album_tree = $this->_tree($root, $parents);
    $v->micro_thumb_grid = $this->_get_micro_thumb_grid($item);
    print $v;
  }

  function content($item_id, $offset=0) {
    $item = ORM::factory("item", $item_id);
    access::required("view", $item);
    access::required("edit", $item);

    $v = $this->_get_micro_thumb_grid($item, $offset);
    print $v->__toString();
  }

  private function _get_micro_thumb_grid($item, $offset=0) {
    $v = new View("organize_thumb_grid.html");
    $v->item_id = $item->id;
    $v->children = $item->children(25, $offset);
    $v->thumbsize = self::$_MICRO_THUMB_SIZE;
    $v->offset = $offset + 25;

    return $v;
  }

  private function _tree($item, $parents) {
    $v = new View("organize_tree.html");
    $v->album = $item;
    $keys = array_keys($parents);
    $v->selected = end($keys) == $item->id;
    $v->children = array();
    $v->album_icon = "gBranchEmpty";

    $albums = $item->children(null, 0, array("type" => "album"), array("title" => "ASC"));
    if ($albums->count()) {
      $v->album_icon = empty($parents[$item->id]) ? "ui-icon-plus" : "ui-icon-minus";

      foreach ($albums as $album) {
        $v->children[] = $this->_tree($album, $parents);
      }
    }
    return $v;
  }
}
