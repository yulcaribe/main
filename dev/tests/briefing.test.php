<?php
// Read only the function definitions; do not dispatch the public HTTP endpoint.
$source=file_get_contents(__DIR__.'/../../briefing/backend.php');
eval(substr(strstr($source,"\n\$from=strtoupper",true),5));
function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
check(parseVertical('SEV TURB FL200/400')['topFL']===400,'Compact FL band');
check(parseVertical('SEV TURB BTN FL200 AND FL400')['bottomFL']===200,'BTN FL band');
check(cruiseRelation(parseVertical('TOP ABV FL320'),360)==='unknown','TOP ABV is not a ceiling');
check(cruiseRelation(parseVertical('TOP FL400'),360)==='unknown','Unknown base does not prove in-layer');
check(cruiseRelation(parseVertical('SFC/FL400'),360)==='at_cruise_level','Bounded layer includes cruise');
check(cruiseRelation(parseVertical('FL200/FL300'),360)==='above_hazard_layer','Cruise above bounded layer');
$feature=['type'=>'Feature','properties'=>['rawSigmet'=>'SEV TURB FL200/FL400','validTimeFrom'=>'2026-09-21T10:00:00Z','validTimeTo'=>'2026-09-21T12:00:00Z'], 'geometry'=>['type'=>'Polygon','coordinates'=>[[[29,36],[31,36],[31,38],[29,38],[29,36]]]]];
$hazards=analyzeSigmets(['features'=>[$feature]],[[37,28],[37,32]],360,strtotime('2026-09-21T13:00:00Z'),strtotime('2026-09-21T15:00:00Z'));
check($hazards[0]['proximity']==='INTERSECTS','Polygon intersects route');
check($hazards[0]['timeRelation']==='outside_flight_window','Expired SIGMET is outside flight window');
echo "8 SIGMET regression checks passed.\n";
