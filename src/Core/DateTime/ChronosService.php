<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\DateTime;

use Windwalker\Core\Runtime\Config;
use Windwalker\Database\DatabaseAdapter;

/**
 * The ChronosManager class.
 */
class ChronosService
{
    /**
     * ChronosService constructor.
     *
     * @param  Config           $config
     * @param  DatabaseAdapter  $db
     */
    public function __construct(
        protected Config $config,
        protected DatabaseAdapter $db
    ) {
        //
    }

    public function getTimezone(): string
    {
        return $this->config->getDeep('app.timezone');
    }

    public function getServerTimezone(): string
    {
        return $this->config->getDeep('app.server_timezone');
    }

    public function getSqlFormat(?DatabaseAdapter $db = null): string
    {
        $db ??= $this->db;

        return $db->getDateFormat();
    }

    public function getNullDate(?DatabaseAdapter $db = null): string
    {
        $db ??= $this->db;

        return $db->getNullDate();
    }

    public function isNullDate(string|int|null|\DateTimeInterface $date, ?DatabaseAdapter $db = null): bool
    {
        $db ??= $this->db;

        return $db->isNullDate($date);
    }

    public function toServer(mixed $date, string|\DateTimeZone $from = null): Chronos
    {
        $from = $from ?? $this->getTimezone();

        return static::convert($date, $from, $this->getServerTimezone());
    }

    public function toServerFormat(
        mixed $date,
        string $format = Chronos::FORMAT_YMD_HIS,
        string|\DateTimeZone $from = null
    ): string {
        return $this->toServer($date, $from)->format($format);
    }

    public function toLocal(mixed $date, string|\DateTimeZone $to = null): Chronos
    {
        $to = $to ?? $this->getTimezone();

        return static::convert($date, $this->getServerTimezone(), $to);
    }

    public function toLocalFormat(
        mixed $date,
        string $format = Chronos::FORMAT_YMD_HIS,
        string|\DateTimeZone $to = null
    ): string {
        return $this->toLocal($date, $to)->format($format);
    }

    public static function convert(
        mixed $date,
        string|\DateTimeZone $from = 'UTC',
        string|\DateTimeZone $to = 'UTC'
    ): Chronos {
        $from = new \DateTimeZone($from);
        $to   = new \DateTimeZone($to);

        if ($from->getName() === $to->getName()) {
            return Chronos::wrap($date);
        }

        $date = Chronos::wrap($date, $from);

        $date = $date->setTimezone($to);

        return $date;
    }

    /**
     * compare
     *
     * @param  string|\DateTimeInterface  $date1
     * @param  string|\DateTimeInterface  $date2
     * @param  string|null                $operator
     *
     * @return  bool|int
     * @throws \Exception
     */
    public static function compare(
        string|\DateTimeInterface $date1,
        string|\DateTimeInterface $date2,
        ?string $operator = null
    ): bool|int {
        $date1 = $date1 instanceof \DateTimeInterface ? $date1 : new \DateTime($date1);
        $date2 = $date2 instanceof \DateTimeInterface ? $date2 : new \DateTime($date2);

        if ($operator === null) {
            return $date1 <=> $date2;
        }

        switch ($operator) {
            case '=':
                return $date1 == $date2;
            case '!=':
                return $date1 != $date2;
            case '>':
            case 'gt':
                return $date1 > $date2;
            case '>=':
            case 'gte':
                return $date1 >= $date2;
            case '<':
            case 'lt':
                return $date1 < $date2;
            case '<=':
            case 'lte':
                return $date1 <= $date2;
        }

        throw new \InvalidArgumentException('Invalid operator: ' . $operator);
    }

    public function createLocal(
        string $date = 'now',
        string|\DateTimeZone $tz = null,
        string|\DateTimeZone $to = null
    ): Chronos {
        $chronos = static::create($date, $tz);

        return $this->toLocal($chronos, $to);
    }

    public function localNow(string $format = Chronos::FORMAT_YMD_HIS, string|\DateTimeZone $tz = null): string
    {
        return $this->createLocal('now', $tz)->format($format);
    }

    /**
     * toFormat
     *
     * @param  string|\DateTimeInterface  $date
     * @param  string                     $format
     *
     * @return string
     *
     * @throws \Exception
     * @since  3.2
     */
    public static function toFormat(string|\DateTimeInterface $date, string $format): string
    {
        return Chronos::toFormat($date, $format);
    }

    /**
     * current
     *
     * @param  string                     $format
     * @param  string|\DateTimeZone|null  $tz
     *
     * @return  string
     * @throws \Exception
     */
    public static function now(string $format = Chronos::FORMAT_YMD_HIS, string|\DateTimeZone $tz = null): string
    {
        return static::create('now', $tz)->format($format);
    }

    /**
     * Proxy for new DateTime.
     *
     * @param  string  $date  String in a format accepted by strtotime(), defaults to "now".
     * @param  mixed   $tz    Time zone to be used for the date.
     *
     * @return  Chronos
     *
     * @throws \Exception
     * @since   2.1
     */
    public static function create(string $date = 'now', string|\DateTimeZone $tz = null): Chronos
    {
        return Chronos::create($date, $tz);
    }

    /**
     * Parse a string into a new DateTime object according to the specified format
     *
     * @param  string  $format    Format accepted by date().
     * @param  string  $time      String representing the time.
     * @param  mixed   $timezone  A DateTimeZone object representing the desired time zone.
     *
     * @return static|bool
     * @throws \Exception
     */
    public static function createFromFormat(
        string $format,
        string $time,
        string|\DateTimeZone $timezone = null
    ): Chronos {
        return Chronos::createFromFormat($format, $time, $timezone);
    }
}
