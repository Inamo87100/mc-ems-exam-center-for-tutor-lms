(function () {
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
    return fetch(NFEMS_BOOKING.ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
      .then(r => r.json());
  }

  // Prenota: carica sessioni per data
  $all("[data-nfems-booking]").forEach(function (wrap) {
    const dateInput = $(".nfems-date", wrap);
    const sessBox   = $(".nfems-sessions", wrap);
    const msgBox    = $(".nfems-msg", wrap);

    function setMsg(t, isErr) {
      msgBox.textContent = t || "";
      msgBox.style.color = isErr ? "crimson" : "";
    }

    function renderSessions(items) {
      sessBox.innerHTML = "";
      if (!items || !items.length) {
        sessBox.innerHTML = "<em>No sessions available for the selected date.</em>";
        return;
      }

      const ul = document.createElement("ul");
      ul.className = "nfems-session-list";
      items.forEach(function (s) {
        const li = document.createElement("li");
        li.innerHTML =
          `<label>
            <input type="radio" name="nfems_session_id" value="${s.id}">
            <strong>${s.time}</strong> ${s.label}
          </label>`;
        ul.appendChild(li);
      });
      sessBox.appendChild(ul);
    }

    if (dateInput) {
      dateInput.addEventListener("change", function () {
        setMsg("");
        renderSessions([]);
        post("nfems_get_sessions_by_date", {
          nonce: NFEMS_BOOKING.nonce,
          date: dateInput.value
        }).then(function (res) {
          if (!res || !res.success) {
            setMsg((res && res.data && res.data.message) ? res.data.message : __('Error loading sessions.', 'mc-ems'), true);
            return;
          }
          renderSessions(res.data.sessions);
        });
      });
    }

    const bookBtn = $(".nfems-book-btn", wrap);
    if (bookBtn) {
      bookBtn.addEventListener("click", function (e) {
        e.preventDefault();
        setMsg("");

        const checked = $("input[name='nfems_session_id']:checked", wrap);
        if (!checked) {
          setMsg("Select an exam session before booking.", true);
          return;
        }

        bookBtn.disabled = true;
        post("nfems_confirm_booking", {
          nonce: NFEMS_BOOKING.nonce,
          session_id: checked.value
        }).then(function (res) {
          bookBtn.disabled = false;
          if (!res || !res.success) {
            setMsg((res && res.data && res.data.message) ? res.data.message : __('Exam booking failed.', 'mc-ems'), true);
            return;
          }
          setMsg(__('Exam booking confirmed!', 'mc-ems'));
          window.location.reload();
        });
      });
    }
  });

  // Gestisci: cancella
  $all("[data-nfems-manage]").forEach(function (wrap) {
    const cancelBtn = $(".nfems-cancel-btn", wrap);
    const msgBox = $(".nfems-msg", wrap);

    function setMsg(t, isErr) {
      msgBox.textContent = t || "";
      msgBox.style.color = isErr ? "crimson" : "";
    }

    if (cancelBtn) {
      cancelBtn.addEventListener("click", function (e) {
        e.preventDefault();
        setMsg("");

        if (!confirm("Do you want to cancel the booking?")) return;

        cancelBtn.disabled = true;
        post("nfems_cancel_booking", { nonce: NFEMS_BOOKING.nonce })
          .then(function (res) {
            cancelBtn.disabled = false;
            if (!res || !res.success) {
              setMsg((res && res.data && res.data.message) ? res.data.message : "Cancellazione non riuscita.", true);
              return;
            }
            setMsg(__('Exam booking cancelled.', 'mc-ems'));
            window.location.reload();
          });
      });
    }
  });
})();
