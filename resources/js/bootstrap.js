// Bootstrap JS, limited to the plugins Novarr uses (Performance stream).
//
// vite.config.js aliases the bare specifier 'bootstrap' to THIS file, so the
// existing `import * as bootstrap from 'bootstrap'` (app.js) and
// `import { Modal } from 'bootstrap'` (confirm.js) / `{ Toast }` (toast.js)
// resolve here instead of pulling in all twelve plugins. The plugins are
// imported from Bootstrap's ES module sources (bootstrap/js/src/*), which
// the alias doesn't match, so there is no cycle.
//
// Each plugin module registers its own data-api handlers on import:
//   Alert     — data-bs-dismiss="alert" (layout flash messages)
//   Collapse  — data-bs-toggle="collapse" (navbar toggler)
//   Dropdown  — data-bs-toggle="dropdown" (navbar, novel page menus); bundles Popper
//   Modal     — confirm dialogs, health page (window.bootstrap.Modal)
//   Offcanvas — reader table of contents
//   Toast     — window.Novarr.showToast
// Need another one (Tooltip, Popover, Tab, ScrollSpy, Carousel, Button)?
// Import it below and add it to the export list.
import Alert from 'bootstrap/js/src/alert.js';
import Collapse from 'bootstrap/js/src/collapse.js';
import Dropdown from 'bootstrap/js/src/dropdown.js';
import Modal from 'bootstrap/js/src/modal.js';
import Offcanvas from 'bootstrap/js/src/offcanvas.js';
import Toast from 'bootstrap/js/src/toast.js';

export { Alert, Collapse, Dropdown, Modal, Offcanvas, Toast };

// Inline Blade scripts (health page, novel page) use window.bootstrap.*.
// app.js also assigns this; setting it here keeps it correct even if that
// line is removed.
window.bootstrap = { Alert, Collapse, Dropdown, Modal, Offcanvas, Toast };
