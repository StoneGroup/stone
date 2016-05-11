<?php namespace Stone\Snap;

class Runkit
{
    /**
     * addStaticSnapMethods
     *
     * @param string $class
     * @return void
     */
    public static function addStaticSnapMethods($class)
    {
        runkit_method_add(
            $class, 'snapStaticNow', '$clazz, $repository',
            '
            $class = new ReflectionClass($clazz);
            $staticData = $class->getStaticProperties();

            if (isset($staticData["snapStaticData"])) {
                unset($staticData["snapStaticData"]);
            }

            $repository->set($clazz, $staticData);
            ',
            RUNKIT_ACC_PUBLIC | RUNKIT_ACC_STATIC
        );

        runkit_method_add(
            $class, 'restoreStaticSnap', '$clazz, $repository',
            '
            $snapStaticData = $repository->get($clazz);
            foreach ($snapStaticData as $key => $value) {
                self::${$key} = $value;
            }
            ',
            RUNKIT_ACC_PUBLIC | RUNKIT_ACC_STATIC
        );
    }

    /**
     * addSnapMethods
     *
     * @param string $class
     * @return void
     */
    public static function addSnapMethods($class)
    {
        runkit_method_add(
            $class, 'snapNow', '$repository',
            '
            $snapData = get_object_vars($this);

            if (isset($snapData["snapData"])) {
                unset($snapData["snapData"]);
            }

            $repository->set(get_class($this), $snapData);
            ',
            RUNKIT_ACC_PUBLIC
        );

        runkit_method_add(
            $class, 'restoreSnap', '$repository',
            '
            $class = get_class($this);
            $snapData = $repository->get($class);

            foreach ($snapData as $key => $value) {
                $this->{$key} = $value;
            }
            ',
            RUNKIT_ACC_PUBLIC
        );
    }
}
