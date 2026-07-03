<?php

namespace Ionutgrecu\LaravelGeo\Traits;

trait HasUlidPrimaryKeyTrait {
    protected static function bootHasUlidPrimaryKeyTrait(): void {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = self::generateCustomId();
            }
        });
    }

    protected static function generateCustomId() {
        $microtime = microtime(true);
        $unixMicro = (int)round($microtime * 1000000);
        $timeHex   = strtoupper(dechex($unixMicro));
        $randomHex = strtoupper(bin2hex(random_bytes(3)));
        return $timeHex . $randomHex;
    }
}