<?php

namespace App\Services;

class VoucherScriptBuilder
{
    public static function usesIsoDate(string $version): bool
    {
        return version_compare(self::plainVersion($version), '7.10', '>=');
    }

    public static function plainVersion(string $version): string
    {
        return explode(' ', trim($version))[0] ?: '0';
    }

    public function loginScript(string $version): string
    {
        $iso = self::usesIsoDate($version);

        $hitungMasa = $iso ? $this->stampIso() : $this->stampLegacy();
        $record     = $iso ? $this->recordIso() : $this->recordLegacy();

        return <<<ROS
:local uid [/ip hotspot user find where name="\$user"]
:if ([:len \$uid] > 0) do={
  :local cmt [/ip hotspot user get \$uid comment]
  :local pre [:pic \$cmt 0 2]
  :if (\$pre = "vc" or \$pre = "up" or \$cmt = "") do={
    :local date [/system clock get date]
    :local time [/system clock get time]
    :if ([:len \$validity] > 0) do={
{$hitungMasa}
    }
    :if (\$record = "yes") do={
{$record}
    }
    :if (\$lock = "yes" and [:len \$mac] > 0) do={
      /ip hotspot user set \$uid mac-address=\$mac
    }
  }
}
ROS;
    }

    private function stampIso(): string
    {
        return <<<'ROS'
      /system scheduler remove [/system scheduler find where name="zn-$user"]
      /system scheduler add name="zn-$user" disabled=no start-date=$date interval=$validity
      :delay 4s
      :local exp [/system scheduler get [/system scheduler find where name="zn-$user"] next-run]
      /system scheduler remove [/system scheduler find where name="zn-$user"]
      :local nx [:len $exp]
      :local newc $exp
      :if ($nx = 8) do={ :set newc "$date $exp" }
      :if ($nx > 7) do={ /ip hotspot user set $uid comment=$newc }
ROS;
    }

    private function stampLegacy(): string
    {
        return <<<'ROS'
      :local yr [:pic $date 7 11]
      /system scheduler remove [/system scheduler find where name="zn-$user"]
      /system scheduler add name="zn-$user" disabled=no start-date=$date interval=$validity
      :delay 4s
      :local exp [/system scheduler get [/system scheduler find where name="zn-$user"] next-run]
      /system scheduler remove [/system scheduler find where name="zn-$user"]
      :local nx [:len $exp]
      :local newc $exp
      :if ($nx = 8) do={ :set newc "$date $exp" }
      :if ($nx = 15) do={
        :local marr {"jan"=1;"feb"=2;"mar"=3;"apr"=4;"may"=5;"jun"=6;"jul"=7;"aug"=8;"sep"=9;"oct"=10;"nov"=11;"dec"=12}
        :local cmo ($marr->[:pic $date 0 3])
        :local emo ($marr->[:pic $exp 0 3])
        :if ($emo < $cmo) do={ :set yr ([:tonum $yr] + 1) }
        :set newc ([:pic $exp 0 6] . "/" . $yr . " " . [:pic $exp 7 15])
      }
      :if ($nx > 7) do={ /ip hotspot user set $uid comment=$newc }
ROS;
    }

    private function recordLegacy(): string
    {
        return <<<'ROS'
      :local mon [:pic $date 0 3]
      :local yir [:pic $date 7 11]
      /system script add name="$date-|-$time-|-$user-|-$price-|-$address-|-$mac-|-$validity-|-$profile-|-$cmt" owner="$mon$yir" source="$date" comment="mikhmon"
ROS;
    }

    private function recordIso(): string
    {
        return <<<'ROS'
      :local marr {"01"="jan";"02"="feb";"03"="mar";"04"="apr";"05"="may";"06"="jun";"07"="jul";"08"="aug";"09"="sep";"10"="oct";"11"="nov";"12"="dec"}
      :local yir [:pic $date 0 4]
      :local mnu [:pic $date 5 7]
      :local dnu [:pic $date 8 10]
      :local mon ($marr->$mnu)
      :local rdate "$mon/$dnu/$yir"
      /system script add name="$rdate-|-$time-|-$user-|-$price-|-$address-|-$mac-|-$validity-|-$profile-|-$cmt" owner="$mon$yir" source="$rdate" comment="mikhmon"
ROS;
    }

    public function profileOnLogin(string $profile, array $p, string $scriptName): string
    {
        $lockLabel = $p['lock'] ? 'Enable' : 'Disable';
        $sprice    = (string) ($p['sprice'] ?? 0);

        if ($p['expmode'] === 'off') {
            return sprintf(':put (",,%s,,,noexp,%s,")', (int) ($p['price'] ?? 0), $lockLabel)
                . ($p['lock'] ? '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]' : '');
        }

        $meta = sprintf(':put (",%s,%s,%s,%s,,%s,")', $p['expmode'], $p['price'], $p['validity'], $sprice, $lockLabel);

        $args = sprintf(
            '$zn user=$user mac=$"mac-address" address=$address profile="%s" validity="%s" price="%s" record="%s" lock="%s"',
            $this->escape($profile),
            $this->escape($p['validity']),
            $this->escape((string) $p['price']),
            $p['record'] ? 'yes' : 'no',
            $p['lock'] ? 'yes' : 'no',
        );

        return $meta
            . '; :local zn [:parse [/system script get [/system script find where name="' . $this->escape($scriptName) . '"] source]]'
            . '; ' . $args;
    }

    public function expireScript(array $profileModes): string
    {
        if (! $profileModes) {
            return '';
        }

        $pairs = [];
        $names = [];
        foreach ($profileModes as $name => $mode) {
            $pairs[] = '"' . $this->escape($name) . '"="' . ($mode === 'rem' ? 'rem' : 'ntf') . '"';
            $names[] = '"' . $this->escape($name) . '"';
        }
        $modes = '{' . implode(';', $pairs) . '}';
        $plist = '{' . implode(';', $names) . '}';

        return <<<ROS
:local modes {$modes}
:local dnum do={
  :if ([:pic \$d 4] = "-") do={ :return [:tonum ([:pic \$d 0 4] . [:pic \$d 5 7] . [:pic \$d 8 10])] }
  :local ma {"jan"="01";"feb"="02";"mar"="03";"apr"="04";"may"="05";"jun"="06";"jul"="07";"aug"="08";"sep"="09";"oct"="10";"nov"="11";"dec"="12"}
  :local mo (\$ma->[:pic \$d 0 3])
  :if ([:typeof \$mo] = "nothing") do={ :return 0 }
  :return [:tonum ([:pic \$d 7 11] . \$mo . [:pic \$d 4 6])]
}
:local tnum do={
  :return ([:tonum [:pic \$t 0 2]] * 3600 + [:tonum [:pic \$t 3 5]] * 60 + [:tonum [:pic \$t 6 8]])
}
:local plist {$plist}
:local now [/system clock get date]
:local jam [/system clock get time]
:local today [\$dnum d=\$now]
:local curt [\$tnum t=\$jam]
:foreach prof in=\$plist do={
  :local mode (\$modes->\$prof)
  :local ids [/ip hotspot user find where profile=\$prof]
  :if (\$mode = "ntf") do={ :set ids [/ip hotspot user find where profile=\$prof limit-uptime!=1s] }
  :foreach i in=\$ids do={
    :local c [/ip hotspot user get \$i comment]
    :local nm [/ip hotspot user get \$i name]
    :local ed 0
    :local et 0
    :local ok 0
    :if ([:pic \$c 4] = "-" and [:pic \$c 7] = "-") do={ :set ed [\$dnum d=\$c]; :set et [\$tnum t=[:pic \$c 11 19]]; :set ok 1 }
    :if ([:pic \$c 3] = "/" and [:pic \$c 6] = "/") do={ :set ed [\$dnum d=\$c]; :set et [\$tnum t=[:pic \$c 12 20]]; :set ok 1 }
    :if (\$ok = 1 and \$ed > 0) do={
      :if (\$ed < \$today or (\$ed = \$today and (\$et < \$curt or \$et = \$curt))) do={
        :if (\$mode = "rem") do={ /ip hotspot user remove \$i } else={ /ip hotspot user set \$i limit-uptime=1s }
        /ip hotspot active remove [find where user=\$nm]
      }
    }
  }
}
ROS;
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }
}
