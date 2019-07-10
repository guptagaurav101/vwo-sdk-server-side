<?php
namespace vwo\Utils;
/**
 *
 *
 */
interface UserProfileInterface {
    public function lookup($userid,$campaignName);
    public function save($campaignInfo);
}