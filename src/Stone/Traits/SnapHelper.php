<?php

namespace Stone\Traits;

use ReflectionClass;

trait SnapHelper
{
    protected $snapData = [];

    protected static $snapStaticData = [];

    public function snapNow()
    {
        $snapData = get_object_vars($this);

        if (isset($snapData['snapData'])) {
            unset($snapData['snapData']);
        }

        $this->snapData = $snapData;
    }

    public function restoreSnap()
    {
        foreach ($this->snapData as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public static function snapStaticNow()
    {
        $class = new ReflectionClass(self::class);
        $staticData = $class->getStaticProperties();

        if (isset($staticData['snapStaticData'])) {
            unset($staticData['snapStaticData']);
        }

        self::$snapStaticData = $staticData;
    }

    public static function restoreStaticSnap()
    {
        foreach (self::$snapStaticData as $key => $value) {
            self::${$key} = $value;
        }
    }
}
