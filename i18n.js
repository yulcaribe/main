(() => {
  "use strict";
  const STORAGE_KEY = "yulcaribe-language";
  const dictionaries = {
    tr: {
      search:"Sorgula", copy:"Kopyala", copied:"Kopyalandı", share:"Paylaş", shared:"Paylaşıldı",
      weatherTitle:"METAR / TAF", stationInput:"ICAO / IATA · ör. LTAI AYT LTFM", quickAirports:"Hızlı havalimanı seçimi",
      loading:"Yükleniyor", noData:"Veri bulunamadı.", noMetar:"METAR bulunamadı.", noTaf:"TAF bulunamadı.",
      enterStations:"1–10 ICAO veya IATA kodu gir.", tooManyStations:"En fazla 10 istasyon sorgulanabilir.", selectProduct:"METAR veya TAF seçeneklerinden en az biri açık olmalı.",
      rawData:"RAW DATA", decodedData:"ÇÖZÜMLEME", source:"Kaynak", shareFallback:"Paylaşım desteklenmiyor; RAW veri panoya kopyalandı.", shareFailed:"Paylaşım açılamadı.",
      wind:"Rüzgar", visibility:"Görüş", cloud:"Bulut", weather:"Hava", temperature:"Sıcaklık", dew:"Çiy noktası", qnh:"QNH", station:"İstasyon", observation:"Gözlem", issued:"Yayın", validity:"Geçerlilik", decoded:"Çözümleme", current:"Mevcut", forecast:"Tahmin", interpretation:"Hava Durumu Yorumu", calm:"Sakin", variable:"Değişken", noSignificant:"Belirgin olumsuz hava işareti yok.", lowVisibility:"Düşük görüş", strongWind:"Kuvvetli rüzgar", gust:"Hamle", cavok:"CAVOK: görüş ve bulut şartları belirgin kısıt göstermiyor.", rawGroup:"Ham grup",
      noUsableMetar:"Kullanılabilir METAR yok; mevcut hava yorumlanamadı.", noWarning:"Çözümlenen gruplarda uyarı bulunmadı; ham METAR’ı inceleyin.", airportNotFound:"Havalimanı bulunamadı.", requestFailed:"Hava verisi alınamadı."
    },
    en: {
      search:"Search", copy:"Copy", copied:"Copied", share:"Share", shared:"Shared",
      weatherTitle:"METAR / TAF", stationInput:"ICAO / IATA · e.g. LTAI AYT LTFM", quickAirports:"Quick airport selection",
      loading:"Loading", noData:"No data available.", noMetar:"METAR unavailable.", noTaf:"TAF unavailable.",
      enterStations:"Enter 1–10 ICAO or IATA codes.", tooManyStations:"A maximum of 10 stations can be queried.", selectProduct:"At least one of METAR or TAF must be selected.",
      rawData:"RAW DATA", decodedData:"DECODED", source:"Source", shareFallback:"Sharing is unavailable; RAW data was copied to the clipboard.", shareFailed:"Share could not be opened.",
      wind:"Wind", visibility:"Visibility", cloud:"Cloud", weather:"Weather", temperature:"Temperature", dew:"Dew point", qnh:"QNH", station:"Station", observation:"Observation", issued:"Issued", validity:"Validity", decoded:"Decoded", current:"Current", forecast:"Forecast", interpretation:"Weather interpretation", calm:"Calm", variable:"Variable", noSignificant:"No significant adverse weather signal detected.", lowVisibility:"Low visibility", strongWind:"Strong wind", gust:"Gust", cavok:"CAVOK: visibility and cloud conditions show no significant restriction.", rawGroup:"Raw group",
      noUsableMetar:"No usable METAR; current conditions cannot be interpreted.", noWarning:"No warning found in the decoded groups; review the raw METAR.", airportNotFound:"Airport not found.", requestFailed:"Weather data could not be retrieved."
    }
  };
  const normalize = value => value === "en" ? "en" : "tr";
  const legacy = localStorage.getItem("yulcaribe-weather-language");
  let language = normalize(localStorage.getItem(STORAGE_KEY) || legacy || "tr");
  localStorage.setItem(STORAGE_KEY, language);
  localStorage.removeItem("yulcaribe-weather-language");
  function t(key, fallback = "") { return dictionaries[language]?.[key] ?? dictionaries.tr[key] ?? fallback ?? key; }
  function setLanguage(next) {
    language = normalize(next);
    localStorage.setItem(STORAGE_KEY, language);
    document.documentElement.lang = language;
    window.dispatchEvent(new CustomEvent("yc:languagechange", {detail:{language}}));
    return language;
  }
  window.YCI18N = { dictionaries, t, getLanguage:() => language, setLanguage, storageKey:STORAGE_KEY };
  document.documentElement.lang = language;
})();