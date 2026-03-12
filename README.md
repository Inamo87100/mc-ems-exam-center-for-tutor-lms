# MC-EMS – Exam Management System

## Descrizione commerciale

### Cosa fa il plugin

MC-EMS è un sistema completo di gestione esami per WordPress, specificamente progettato per piattaforme e-learning che utilizzano Tutor LMS. Consente alle scuole, università e centri di formazione di:

- Gestire sessioni d'esame con calendari intuitivi e supervisori assegnati
- Permettere ai candidati di prenotarsi agli esami direttamente dal frontend
- Controllare l'accesso ai corsi basato sulle prenotazioni agli esami
- Assegnare proctors (supervisori) agli esami
- Automatizzare le comunicazioni via email
- Generare sessioni in batch (singolo in Base, multipli in Premium)
- Impostare una finestra di anticipo prenotazione (ore configurabili)
- Limitare la cancellazione prenotazione entro un numero di ore configurabile prima dell'esame
- Consentire prenotazioni per-corso (ogni studente può avere una prenotazione attiva per ogni corso)
- Integrare Google Calendar per aggiungere l'esame direttamente al calendario personale
- Configurare lead time e durata dell'accesso ai corsi prima e dopo la sessione d'esame
- Gestire sessioni speciali per candidati con esigenze di accessibilità
- Visualizzare liste prenotazioni avanzate con filtri e range date (Premium)
- Esportare dati prenotazioni in CSV (Premium)

---

## Funzionalità Base

### Gestione sessioni d'esame

- Creazione di sessioni d'esame come Custom Post Type (`mcems_exam_session`)
- Impostazione data, ora, capienza massima e supervisore
- Assegnazione supervisori (proctors) con notifiche email automatiche
- Sessioni passate in sola lettura nel backend
- Blocco automatico: impossibile creare sessioni con data nel passato
- Sessioni speciali per candidati con esigenze di accessibilità (capienza = 1, visibile solo all'utente assegnato)
- **Limite Base:** max 5 sessioni future attive, max 5 posti per sessione, 1 batch al giorno

### Generazione sessioni in batch

- Creazione di più sessioni d'esame in una singola operazione
- Selezione del corso, data, orario e capienza
- **Base:** 1 batch al giorno, max 5 sessioni future, max 5 posti per sessione
- **Premium:** batch illimitati, sessioni illimitate, capienza illimitata

### Prenotazioni candidati

Shortcode: `[mcems_book_exam]`

- Calendario interattivo per selezionare data e orario dell'esame
- Filtro per corso (ogni studente vede solo le sessioni del proprio corso)
- Prenotazioni per-corso: ogni studente può avere una prenotazione attiva per corso (multi-booking su più corsi)
- Disponibilità in tempo reale con codice colore:
  - Verde: posti abbondanti (≥ 50% disponibilità)
  - Giallo: posti in esaurimento (25–50%)
  - Arancione: ultimo posto
  - Rosso: completo
- Finestra di anticipo prenotazione configurabile: è possibile prenotarsi solo se l'esame è a ≥ N ore di distanza (default: 48 ore)
- Aggiunta al Google Calendar dalla conferma di prenotazione

### Gestione prenotazioni personali

Shortcode: `[mcems_manage_booking]`

- Elenco di tutte le prenotazioni attive del candidato, per corso
- Cancellazione prenotazione con controllo sulla finestra temporale: è possibile cancellare solo se l'esame è a ≥ N ore di distanza (default: 48 ore)
- La cancellazione può essere disabilitata completamente dalle impostazioni
- Notifiche email automatiche al candidato e all'admin

### Calendario supervisori

Shortcode: `[mcems_sessions_calendar]`

- Visualizzazione calendario di tutte le sessioni (mese/settimana/giorno)
- Assegnazione e riassegnazione proctors direttamente dal calendario
- Controllo automatico ogni 24 ore per sessioni senza proctor assegnato, con email di avviso

### Lista prenotazioni (Base)

Shortcode: `[mcems_bookings_list]`

- Filtro per singola data e per corso
- Tabella con: cognome, nome, email, ID sessione, data, ora, corso, sessione speciale, supervisore
- Esportazione CSV

### Controllo accesso corsi (Tutor LMS Gate)

- Blocco automatico dell'accesso ai corsi per studenti senza prenotazione valida
- Lead time configurabile: accesso abilitato N minuti prima dell'inizio della sessione (default: 0)
- Durata accesso post-esame configurabile: accesso disponibile per N ore/minuti dopo la sessione (default: 0)
- Selezione dei corsi protetti dal gate (checkbox per corso)
- Bypass automatico per admin e insegnanti
- Redirect verso la pagina di prenotazione se l'accesso è bloccato

### Notifiche email

Tutte le email sono configurabili (mittente, destinatari admin, oggetto, corpo con placeholder):

| Evento | Destinatario | Default |
|--------|-------------|---------|
| Prenotazione confermata | Candidato | Abilitata |
| Prenotazione cancellata | Candidato | Abilitata |
| Nuova prenotazione | Admin | Disabilitata |
| Prenotazione cancellata | Admin | Disabilitata |
| Proctor assegnato | Proctor | Disabilitata |
| Proctor rimosso | Proctor | Disabilitata |
| Sessione senza proctor (24h) | Admin | Abilitata |

Placeholder disponibili: `{candidate_name}`, `{candidate_email}`, `{course_title}`, `{session_date}`, `{session_time}`, `{session_id}`, `{manage_booking_url}`, `{proctor_name}`

### Impostazioni (5 tab)

**Tab Prenotazioni**
- Ore di anticipo minimo per prenotarsi (default: 48)
- Abilita/disabilita cancellazione prenotazione
- Ore minime prima della sessione per poter cancellare (default: 48)

**Tab Accesso corsi**
- Abilita/disabilita il gate di accesso
- Selezione corsi protetti
- Selezione corsi visibili nel calendario di prenotazione
- Lead time in minuti (accesso prima della sessione)
- Durata accesso post-sessione (valore + unità: minuti o ore)

**Tab Email**
- Mittente (nome + email)
- Destinatari admin (uno o più indirizzi)
- Toggle abilita/disabilita per ogni tipo di notifica
- Editor template per ogni email
- Pannello placeholder con copia rapida

**Tab Pagine**
- Seleziona la pagina contenente `[mcems_book_exam]`
- Seleziona la pagina contenente `[mcems_manage_booking]`

**Tab Shortcodes**
- Guida rapida all'uso degli shortcodes disponibili

---

## Funzionalità Premium

Il plugin Premium si installa come add-on separato e richiede il plugin Base attivo.

### Limiti rimossi

- Sessioni future attive: **illimitate**
- Posti per sessione: **illimitati**
- Batch giornalieri: **illimitati**

### Lista prenotazioni avanzata

Shortcode: `[mcems_bookings_list]` (sostituisce la versione Base)

- Tutti i filtri della versione Base
- Filtro per **range di date** (da data / a data) in aggiunta al filtro singola data
- Pannello filtri avanzati mostrabile/nascondibile
- Esportazione CSV con range date
- Stesse colonne della versione Base: cognome, nome, email, ID sessione, data, ora, corso, sessione speciale, supervisore

---

## Tabella comparativa

| Funzionalità | Base | Premium |
|---|---|---|
| Gestione sessioni d'esame | ✅ | ✅ |
| Sessioni speciali (accessibilità) | ✅ | ✅ |
| Calendario prenotazione candidati | ✅ | ✅ |
| Prenotazioni per-corso (multi-booking) | ✅ | ✅ |
| Finestra anticipo prenotazione (ore) | ✅ | ✅ |
| Cancellazione controllata (ore prima) | ✅ | ✅ |
| Google Calendar integration | ✅ | ✅ |
| Gestione prenotazioni personali | ✅ | ✅ |
| Calendario supervisori | ✅ | ✅ |
| Controllo accesso corsi (gate) | ✅ | ✅ |
| Lead time e durata accesso corsi | ✅ | ✅ |
| Notifiche email (7 tipi) | ✅ | ✅ |
| Lista prenotazioni con filtro data singola | ✅ | ✅ |
| Esportazione CSV | ✅ | ✅ |
| **Sessioni future max** | **5** | **Illimitate** |
| **Posti per sessione max** | **5** | **Illimitati** |
| **Batch giornalieri** | **1** | **Illimitati** |
| Filtro range date nella lista prenotazioni | ❌ | ✅ |
| Filtri avanzati nella lista prenotazioni | ❌ | ✅ |

---

## Casi d'uso

**Università o istituto con esami periodici**
Crea sessioni mensili per ogni corso, lascia che gli studenti si prenotino online e blocca l'accesso al materiale del corso finché non c'è una prenotazione valida. Ricevi email automatiche ad ogni nuova prenotazione o cancellazione.

**Centro di formazione professionale**
Usa le sessioni speciali per candidati con esigenze di accessibilità. Configura un anticipo di prenotazione di 72 ore e vieta la cancellazione nell'ultima ora prima dell'esame. Assegna supervisori e ricevi avviso automatico se una sessione rimane senza proctor.

**Piattaforma online con molti corsi**
Con la versione Premium, crea sessioni illimitate senza preoccuparti dei limiti giornalieri. Usa la lista prenotazioni con filtro per range date per esportare i dati mensili in CSV e condividerli con la direzione.

---

## Vantaggi

- Riduce il carico amministrativo: prenotazioni, notifiche e controllo accessi sono automatizzati
- Gli studenti gestiscono in autonomia prenotazioni e cancellazioni dal frontend
- Il gate di accesso ai corsi garantisce che solo i candidati prenotati accedano ai contenuti
- La configurazione delle ore di anticipo e cancellazione previene i no-show dell'ultimo minuto
- L'integrazione con Google Calendar aiuta i candidati a non dimenticare l'esame
- I supervisor ricevono notifiche e hanno una vista calendario dedicata
- Tutti i template email sono personalizzabili senza modificare il codice

---

## Setup

1. Installa e attiva **mc-ems-base**
2. (Opzionale) Installa e attiva **mc-ems-premium** per rimuovere i limiti e aggiungere i filtri avanzati
3. Crea una pagina WordPress e inserisci `[mcems_book_exam]`
4. Crea una seconda pagina e inserisci `[mcems_manage_booking]`
5. Vai su **Impostazioni → MC-EMS → Pagine** e seleziona le due pagine
6. Vai su **Impostazioni → MC-EMS → Accesso corsi** e seleziona i corsi da proteggere
7. Vai su **Impostazioni → MC-EMS → Email** e configura mittente e template
8. Crea le prime sessioni d'esame dal menu **MC-EMS → Sessioni**

---

## Requisiti

- WordPress 6.0+
- PHP 7.0+
- Tutor LMS (per l'integrazione con i corsi e il gate di accesso)
