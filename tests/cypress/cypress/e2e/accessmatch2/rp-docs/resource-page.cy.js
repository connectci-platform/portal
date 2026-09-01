describe("Resource Documentation Page — Alpha (full data)", () => {

  beforeEach(() => {
    cy.visit("/documentation/resources/alpha");
  });

  it("renders the page title and status badges", () => {
    cy.contains("Test Resource Alpha");
    cy.contains("2FA/MFA");
    cy.contains("RP account needed");
  });

  it("renders login text above the login boxes", () => {
    cy.get(".rp-login .prose").should("exist");
    cy.get(".rp-login .prose").should("contain", "getting started guide");
  });

  it("renders the SSH login section with dropdown", () => {
    cy.get("#rp-ssh-login-select").should("exist");
    cy.get("#rp-ssh-login-select option").should("have.length", 3);
    cy.contains("RECOMMENDED");
    cy.get("#rp-ssh-hostname").should("contain", "login01.alpha.test.example.edu");
  });

  it("SSH dropdown updates the displayed hostname and placeholder", () => {
    cy.get("#rp-ssh-login-select").select("login02.alpha.test.example.edu");
    cy.get("#rp-ssh-hostname").should("contain", "login02.alpha.test.example.edu");
    // login02 has no explicit placeholder — falls back to the default.
    cy.get("#rp-ssh-placeholder").should("contain", "<your_username>");
  });

  it("SSH dropdown shows docs link when a node has one", () => {
    // login03 has a docs URL configured; switching to it should reveal the link.
    cy.get("#rp-ssh-docs").should("have.class", "hidden");
    cy.get("#rp-ssh-login-select").select("login03.alpha.test.example.edu");
    cy.get("#rp-ssh-docs").should("not.have.class", "hidden");
    cy.get("#rp-ssh-docs-link").should("have.attr", "href").and("include", "docs.example.edu/alpha/login03");
  });

  it("renders the OnDemand login button with the editor-provided label", () => {
    // Section heading is fixed; the button label comes from the link field's
    // optional title (uppercased) — falls back to "LOGIN" when blank.
    cy.contains("ACCESS OnDemand Login");
    cy.contains("ACCESS ONDEMAND").should("have.attr", "href").and("include", "ondemand.alpha.test.example.edu");
  });

  it("renders login help links outside the SSH box", () => {
    cy.get(".rp-login").contains("Watch video: INTRODUCTION TO SSH KEYS");
  });

  it("renders the Jump-to anchor nav with icons", () => {
    cy.get(".rp-jump-to").should("exist");
    cy.get(".rp-jump-to").within(() => {
      cy.contains("Login").should("have.attr", "href", "#rp-login");
      cy.contains("File Transfer").should("have.attr", "href", "#rp-file-transfer");
      cy.contains("Storage").should("have.attr", "href", "#rp-storage");
      cy.contains("Jobs").should("have.attr", "href", "#rp-jobs");
      cy.contains("Software").should("have.attr", "href", "#rp-software");
      cy.contains("Datasets").should("have.attr", "href", "#rp-datasets");
    });
  });

  it("renders the RP Account Setup CTA in the sidebar", () => {
    // Sidebar uses CIDeR short_name ("Alpha") rather than the long descriptive title.
    cy.get(".rp-sidebar").contains("GET AN ACCOUNT ON ALPHA");
    cy.get(".rp-sidebar").contains("Set up your Alpha account")
      .should("have.attr", "href")
      .and("include", "alpha.test.example.edu/account");
  });

  it("renders the file transfer table without Globus boilerplate", () => {
    cy.contains("h2", "File Transfer");
    cy.get(".rp-file-transfer table tbody tr").should("have.length", 3);
    cy.contains("GLOBUS");
    cy.get(".rp-file-transfer").contains("RECOMMENDED");
    cy.get(".rp-file-transfer").should("not.contain", "Use Globus for large transfers");
    // Editor-provided link text overrides the raw URL.
    cy.get(".rp-file-transfer").contains("a", "Globus").should("have.attr", "href").and("include", "app.globus.org");
    cy.get(".rp-file-transfer").should("not.contain", "https://app.globus.org");
  });

  it("renders the storage table with plain text paths (no code tags)", () => {
    cy.contains("h2", "Storage");
    cy.get(".rp-storage table tbody tr").should("have.length", 4);
    cy.contains("td", "Home");
    cy.contains("td", "Scratch");
    cy.get(".rp-storage table td").contains("/home/<username>").then(($td) => {
      expect($td.find("code").length).to.equal(0);
    });
  });

  it("renders quota size and inode count in one cell, TB for large quotas", () => {
    // Home: 25 GB + 1,000,000 inodes, in a single cell (size then files).
    cy.get(".rp-storage table tbody tr").contains("td", "Home")
      .parent("tr").should("contain", "25 GB").and("contain", "1,000,000 files");
    // Project: 10000 GB renders as decimal TB.
    cy.get(".rp-storage table tbody tr").contains("td", "Project")
      .parent("tr").should("contain", "10 TB");
    // The old transposed rendering ("…GB … files") is gone.
    cy.get(".rp-storage table").should("not.contain", "GB files");
  });

  it("renders external storage section", () => {
    cy.contains("External Storage");
    cy.contains("GETTING MORE STORAGE");
  });

  it("renders jobs info text above queue specs", () => {
    cy.contains("Alpha uses Slurm for job scheduling");
    cy.contains("sbatch");
  });

  it("renders queue specs table", () => {
    cy.contains("h2", "Jobs");
    cy.get(".rp-queue-specs table tbody tr").should("have.length", 5);
    cy.contains("td", "gpu-standard");
    cy.contains("td", "gpu-large");
    cy.contains("td", "debug");
    cy.contains("td", "cpu-shared");
    cy.contains("td", "gpu-cloud");
  });

  it("renders corrected GPU and Node RAM columns", () => {
    // Column relabeled away from the ambiguous "RAM" (field_rp_vram is node RAM).
    cy.get(".rp-queue-specs").contains("th", "Node RAM");
    // Per-node column labels: CPU count reads as cores per node, GPUs per node.
    cy.get(".rp-queue-specs").contains("th", "CPU cores / node");
    cy.get(".rp-queue-specs").contains("th", "GPUs / node");
    // GPU cell: "<count> <type> (<vram> GB vRAM)" and Node RAM in GB.
    cy.get(".rp-queue-specs table tbody tr").contains("td", "gpu-standard")
      .parent("tr").within(() => {
        cy.contains("4 NVIDIA A100 (80 GB vRAM)");
        cy.contains("256 GB");
        // CPU core count is parenthesized like the GPU vRAM.
        cy.contains("AMD EPYC 7763 (64 cores)");
      });
    // CPU-only queue shows a dash for GPU, never "0 …".
    cy.get(".rp-queue-specs table tbody tr").contains("td", "cpu-shared")
      .parent("tr").should("contain", "—").and("not.contain", "0 NVIDIA");
    // gpu-cloud has GPU type + vRAM but no per-node count: render type/vRAM
    // without a leading count, not an em-dash.
    cy.get(".rp-queue-specs table tbody tr").contains("td", "gpu-cloud")
      .parent("tr").should("contain", "NVIDIA H100 (80 GB vRAM)")
      .and("not.contain", "0 NVIDIA H100");
  });

  it("renders the max wall time on the wall-time cell", () => {
    // The wall-time limit renders as text below the sparkline ("limit <chip>",
    // compact minutes-aware form) plus a red ceiling line inside the SVG.
    // Numbers (range/avg/limit) live in the text row, not inside the chart.
    // gpu-standard: 2880 min -> "limit 48h", drawn as a red line + text.
    cy.get(".rp-queue-specs table tbody tr").contains("td", "gpu-standard")
      .parent("tr").within(() => {
        cy.contains("limit 48h");
        cy.get("svg line[stroke='#c0392b']").should("exist"); // red ceiling line
        cy.get("svg polyline").should("exist"); // trend not flattened
      });
    // gpu-large: 7200 min -> "limit 120h" (the 30-day usage exceeds it — line drawn, text shown).
    cy.get(".rp-queue-specs table tbody tr").contains("td", "gpu-large")
      .parent("tr").should("contain", "limit 120h");
    // debug: 30 min -> sub-hour "limit 30m" chip + red line inside the SVG.
    cy.get(".rp-queue-specs table tbody tr").contains("td", "debug")
      .parent("tr").within(() => {
        cy.contains("limit 30m");
        cy.get("svg line[stroke='#c0392b']").should("exist"); // red ceiling line
      });
    // cpu-shared has no limit set -> no "limit" text, no red line.
    cy.get(".rp-queue-specs table tbody tr").contains("td", "cpu-shared")
      .parent("tr").within(() => {
        cy.contains("limit").should("not.exist");
        cy.get("svg line[stroke='#c0392b']").should("not.exist");
      });
  });

  it("renders the per-partition Nodes column", () => {
    cy.get(".rp-queue-specs").contains("th", "Num nodes");
    // gpu-standard has field_rp_node_count = 100 in the fixture.
    cy.get(".rp-queue-specs table tbody tr").contains("td", "gpu-standard")
      .parent("tr").should("contain", "100");
    // cpu-shared has field_rp_node_count = 200.
    cy.get(".rp-queue-specs table tbody tr").contains("td", "cpu-shared")
      .parent("tr").should("contain", "200");
    // gpu-cloud has an unknown node count: the cell reads "N/A", not 0.
    // Resolve the Num nodes column index from the header so the assertion
    // survives column reordering, then check that column in gpu-cloud's row.
    cy.get(".rp-queue-specs table thead th").then(($ths) => {
      const nodesCol = [...$ths].findIndex((th) => th.textContent.trim() === "Num nodes");
      expect(nodesCol, "Num nodes column present").to.be.greaterThan(-1);
      cy.get(".rp-queue-specs table tbody tr").contains("td", "gpu-cloud")
        .parent("tr").find("td").eq(nodesCol).should("contain", "N/A");
    });
  });

  it("renders top software table", () => {
    cy.contains("h2", "Software");
    cy.contains("h3", "Most Frequently Used");
    // Scope to the top-software table specifically — the OOD software table
    // now lives in the same #rp-software section, so an unscoped row count
    // would catch both tables' rows.
    cy.get(".rp-top-software-table table tbody tr").should("have.length", 5);
    cy.contains("td", "python");
    cy.contains("td", "gromacs");
    cy.get(".rp-top-software-table").contains("XDMoD");
  });

  it("renders datasets table", () => {
    cy.contains("h2", "Datasets");
    cy.get(".rp-datasets table tbody tr").should("have.length", 2);
    cy.get(".rp-datasets table").contains("td", "ImageNet-1K");
    cy.get(".rp-datasets table").contains("td", "Common Crawl (2024)");
  });

  it("renders the OOD software subsection as an enriched table", () => {
    cy.contains("h3", "Available via ACCESS OnDemand");
    // Rendered as a table (like top software) so SDS enrichment is visible:
    // Application / Description / Research Discipline columns.
    cy.get(".rp-ood-software").contains("th", "Research Discipline");
    // Both entries are SDS-enriched (students enter only names; cron fills the
    // rest). Name links to web_page, description + research field are shown.
    cy.get(".rp-ood-software").contains("a", "Jupyter").should("have.attr", "href").and("include", "jupyter.org");
    cy.get(".rp-ood-software").contains("Computer & Information Sciences");
    cy.get(".rp-ood-software").contains("a", "RStudio").should("have.attr", "href").and("include", "posit.co");
    cy.get(".rp-ood-software").contains("Other Natural Sciences");
  });

  it("renders sidebar with support links (Alpha's own values override the Group)", () => {
    cy.get(".rp-sidebar").within(() => {
      cy.contains("Get Support");
      // Alpha sets its own support_links, so Group values must not leak through.
      cy.contains("User Guide");
      cy.contains("Ticket System");
      cy.should("not.contain.text", "Group User Guide");
      cy.should("not.contain.text", "Group Ticket System");

      cy.contains("OFFICE HOURS");
      cy.contains("Mon 2-4 PM EST");
      cy.should("not.contain.text", "Fri 3-5 PM EST");
    });
  });

  it("renders software section CTA (Alpha's own value, not the Group's)", () => {
    // CTA moved from .rp-sidebar to .rp-software-sidebar in the Software section.
    // The link label is now standardized ("See Provider Software Documentation")
    // regardless of the RP-provided title, so distinguish resource vs group by href.
    cy.get(".rp-software-sidebar")
      .contains("a", "See Provider Software Documentation")
      .should("have.attr", "href")
      .and("include", "alpha.test.example.edu/software")
      .and("not.include", "group.test.example.edu/software");
  });

  it("uses 'Software Documentation Service' label for SDS", () => {
    cy.contains("Software Documentation Service");
  });

  it("QA bot has resource group context", () => {
    cy.get(".embedded-qa-bot")
      .should("have.attr", "data-scope-slug", "test-resource-group");
  });

});

describe("Resource Documentation Page — Beta (sparse data, in Test Resource Group)", () => {

  beforeEach(() => {
    cy.visit("/documentation/resources/beta");
  });

  it("renders the title and description", () => {
    cy.contains("Test Resource Beta");
    cy.contains("CPU-only cluster");
  });

  it("does not render empty main-content sections", () => {
    cy.get(".rp-file-transfer").should("not.exist");
    cy.get(".rp-storage").should("not.exist");
    cy.get(".rp-queue-specs").should("not.exist");
    // .rp-top-software renders for Beta because it inherits software_list_url
    // from the Group, even without its own top_software data.
    cy.get(".rp-datasets").should("not.exist");
    cy.get(".rp-login").should("not.exist");
  });

  it("jump-to anchor nav lists only the sections Beta actually has", () => {
    // Beta's only main-content section is Software (inherited software_list_url
    // from the Group, per the section test above), so the nav renders with just
    // that one link — not absent, and not listing the empty sections.
    cy.get(".rp-jump-to").should("exist");
    cy.get(".rp-jump-to").contains("Software");
    cy.get(".rp-jump-to").should("not.contain.text", "Login");
    cy.get(".rp-jump-to").should("not.contain.text", "File Transfer");
    cy.get(".rp-jump-to").should("not.contain.text", "Storage");
    cy.get(".rp-jump-to").should("not.contain.text", "Jobs");
    cy.get(".rp-jump-to").should("not.contain.text", "Datasets");
  });

  it("does not show MFA or account badges", () => {
    cy.contains("2FA/MFA").should("not.exist");
    cy.contains("RP account needed").should("not.exist");
  });

  it("inherits support_links from the Resource Group", () => {
    cy.get(".rp-sidebar").within(() => {
      cy.contains("Get Support");
      cy.contains("Group User Guide");
      cy.contains("Group Ticket System");
    });
  });

  it("inherits office_hours from the Resource Group", () => {
    cy.get(".rp-sidebar").within(() => {
      cy.contains("OFFICE HOURS");
      cy.contains("Fri 3-5 PM EST");
    });
  });

  it("inherits software_list_url from the Resource Group", () => {
    // CTA moved from .rp-sidebar to .rp-software-sidebar. Label is the
    // standardized "See Provider Software Documentation"; the inherited
    // group value is confirmed via the href.
    cy.get(".rp-software-sidebar")
      .contains("a", "See Provider Software Documentation")
      .should("have.attr", "href")
      .and("include", "group.test.example.edu/software");
  });

});

describe("Resource Documentation Page — Gamma (partial data)", () => {

  beforeEach(() => {
    cy.visit("/documentation/resources/gamma");
  });

  it("renders storage but not file transfer or queues", () => {
    cy.get(".rp-storage").should("exist");
    cy.get(".rp-storage table tbody tr").should("have.length", 2);
    cy.get(".rp-file-transfer").should("not.exist");
    cy.get(".rp-queue-specs").should("not.exist");
  });

  it("shows MFA badge but not account badge", () => {
    cy.contains("2FA/MFA");
    cy.contains("RP account needed").should("not.exist");
  });

  it("renders login section with help links but no SSH or OnDemand", () => {
    // Gamma has login_help_links and account_setup_url but no SSH logins or OnDemand
    cy.get(".rp-login").should("exist");
    cy.get("#rp-ssh-login-select").should("not.exist");
    cy.contains("ACCESS OnDemand Login").should("not.exist");
    cy.get(".rp-login").contains("Gamma SSH Guide");
  });

  it("renders RP Account Setup CTA in the sidebar", () => {
    // Sidebar uses CIDeR short_name ("Gamma") rather than the long descriptive title.
    cy.get(".rp-sidebar").contains("GET AN ACCOUNT ON GAMMA");
    cy.get(".rp-sidebar").contains("Set up your Gamma account");
  });

  it("jump-to nav only includes sections that exist", () => {
    cy.get(".rp-jump-to").should("exist");
    cy.get(".rp-jump-to").contains("Storage");
    cy.get(".rp-jump-to").should("not.contain.text", "File Transfer");
    cy.get(".rp-jump-to").should("not.contain.text", "Jobs");
  });

  it("renders support sidebar with office hours from its own values (not inherited)", () => {
    cy.get(".rp-sidebar").within(() => {
      cy.contains("Support Portal");
      cy.contains("Tue/Thu 10 AM - 12 PM CST");
      // Gamma isn't in the Test Resource Group, so Group values never apply.
      cy.should("not.contain.text", "Group User Guide");
      cy.should("not.contain.text", "Fri 3-5 PM EST");
    });
  });

  it("QA bot falls back to resource title (short_name post-load-hook) when not in a group", () => {
    cy.get(".embedded-qa-bot")
      .should("have.attr", "data-scope-slug", "gamma");
  });

});

describe("Resource Documentation Page — expandable intro", () => {

  it("clamps Alpha's long multi-block intro with a working toggle", () => {
    cy.visit("/documentation/resources/alpha");
    cy.get(".rp-description .expandable-text").should("exist");
    cy.get(".rp-description .expandable-text.is-collapsed").should("exist");
    cy.get(".rp-description .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "false")
      .and("contain.text", "More");
    // The trailing paragraph is present but clipped via the content wrapper's
    // inline max-height + overflow:hidden (set by expandable-text.js) while
    // collapsed. The clipped paragraph can still report a nonzero
    // offsetHeight/Width to jQuery's :visible check (it's clipped by an
    // ancestor's max-height, not its own display/visibility), so assert on
    // the DOM state the JS actually toggles instead: the inline max-height
    // and the is-collapsed class.
    cy.contains(".rp-description p", "Consult the scheduler notes").should("exist");
    cy.get(".rp-description .expandable-text__content")
      .should("have.attr", "style")
      .and("match", /max-height/);
    cy.get(".rp-description .expandable-text__toggle").click();
    cy.get(".rp-description .expandable-text").should("not.have.class", "is-collapsed");
    cy.get(".rp-description .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "true")
      .and("contain.text", "Less");
    cy.get(".rp-description .expandable-text__content")
      .invoke("attr", "style")
      .should("satisfy", (style) => !style || !/max-height/.test(style));
    cy.contains(".rp-description p", "Consult the scheduler notes").should("be.visible");
  });

  it("shows no toggle for Beta's short intro", () => {
    cy.visit("/documentation/resources/beta");
    // Beta's description is a single short paragraph (< 4 lines).
    cy.get(".rp-description .expandable-text__toggle").should("not.exist");
  });

});
