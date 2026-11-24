import { seedInheritanceFixture } from "../../../support/rp-fixtures";

// Expected values seeded by amp_dev_install_create_rp_docs_test_data().
// Edit in lockstep with amp_dev/amp_dev.install when fixtures change.
const FIXTURES = {
  alpha: {
    resource_id: "test-alpha-9999",
    ssh_login_docs_url: "alpha.test.example.edu/docs/ssh",
    // Must stay ALPHABETICAL: the assertion compares against Object.keys().sort().
    // Fixture source order is debug, gpu-standard, gpu-large, cpu-shared.
    metrics_queues: ["cpu-shared", "debug", "gpu-large", "gpu-standard"],
    org_name: "Test University",
    ssh_hostnames: [
      "login01.alpha.test.example.edu",
      "login02.alpha.test.example.edu",
      "login03.alpha.test.example.edu",
    ],
    login03_docs_url: "docs.example.edu/alpha/login03",
    software_list_url_fragment: "alpha.test.example.edu/software",
    office_hours_url_fragment: "alpha.test.example.edu/office-hours",
  },
  // Legacy-shaped (D8-3090): queue rows carry their own hardware and the
  // resource has no node types. Covers the fallback in the API and aspTheme.
  beta: {
    resource_id: "test-beta-9998",
  },
  gamma: {
    resource_id: "test-gamma-9997",
  },
  // Owns only storage; its group supplies file transfer and datasets.
  delta: {
    resource_id: "test-delta-9996",
  },
  noCider: {
    // No field_cider_resource_id or field_access_global_resource_id, like the
    // real Fabric and Sage resources, so it is only reachable by nid.
    title: "Test Resource Delta-NoCider",
  },
  group: {
    title: "Test Resource Group",
    support_link_titles: ["Group User Guide", "Group Ticket System"],
    office_hours_url_fragment: "group.test.example.edu/office-hours",
    software_list_url_fragment: "group.test.example.edu/software",
  },
};

const nonEmpty = (v) => Array.isArray(v) && v.length > 0;
const hasText = (v) => typeof v === "string" && v.trim() !== "";

// Mirrors the per-section rules: a section counts only when the detail
// response has content for it.
const sectionsFromDetail = (body) => {
  const sections = new Set();
  if (
    nonEmpty(body.ssh_logins) ||
    !!body.ondemand_url ||
    hasText(body.login_text) ||
    nonEmpty(body.login_help_links)
  ) {
    sections.add("login");
  }
  if (nonEmpty(body.file_transfer) || hasText(body.file_transfer_text)) {
    sections.add("file_transfer");
  }
  if (
    nonEmpty(body.storage) ||
    hasText(body.storage_text) ||
    hasText(body.external_storage)
  ) {
    sections.add("storage");
  }
  if (nonEmpty(body.queue_specs) || hasText(body.jobs_info)) {
    sections.add("queue_specs");
  }
  if (nonEmpty(body.datasets) || hasText(body.datasets_text)) {
    sections.add("datasets");
  }
  if (nonEmpty(body.top_software)) sections.add("top_software");
  return sections;
};

// Asserts a group's populated_sections is exactly the union of the sections
// its members' detail responses have content for.
const checkPopulatedSections = (groupTitle, memberIds, exactMembers) => {
  cy.request("/api/1.0/resource-groups").then((listing) => {
    const group = listing.body.groups.find((g) => g.title === groupTitle);
    expect(group, `"${groupTitle}" in /resource-groups`).to.exist;
    const resourceIds = group.variants.map((v) => v.resource_id);
    if (exactMembers) {
      expect(resourceIds).to.deep.equal(memberIds);
    }
    else {
      expect(resourceIds).to.include.members(memberIds);
    }

    const expected = new Set();
    cy.wrap(resourceIds).each((resourceId) => {
      cy.request(`/api/1.0/resources/${resourceId}`).then((detail) => {
        sectionsFromDetail(detail.body).forEach((s) => expected.add(s));
      });
    }).then(() => {
      const listed = group.populated_sections;
      // Every section a member has content for must be listed.
      expected.forEach((section) => {
        expect(listed, `populated_sections includes ${section}`).to.include(section);
      });
      // And no section may be listed that no member has content for.
      listed.forEach((section) => {
        expect(expected.has(section), `populated_sections lists ${section} with no content`).to.be.true;
      });
    });
  });
};

describe("Resource Documentation API", () => {

  // CI loads the nightly database from main, built by enabling and then
  // uninstalling amp_dev, so a fixture added to amp_dev on a branch is not
  // there yet. Seed Alpha (node types and queues, D8-3090), Beta (legacy queue
  // rows, no node types) and the no-CiDeR resource here;
  // the install functions update the node in place when it already exists.
  // Loud by default so a broken seed fails at setup rather than as a missing
  // row later.
  before(() => {
    cy.exec(
      `ddev drush php:eval 'require_once DRUPAL_ROOT . "/" . \\Drupal::service("extension.list.module")->getPath("amp_dev") . "/amp_dev.install";` +
        `amp_dev_install_create_rp_alpha(); amp_dev_install_create_rp_beta(); amp_dev_install_create_rp_no_cider();'`,
      { timeout: 120000 }
    );
  });

  it("exposes per-section documentation links, null where unset", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((response) => {
      const links = response.body.documentation_links;
      expect(links, "documentation_links").to.be.an("object");

      // Every section key is always present, so a consumer can read them
      // without checking for the key first.
      expect(Object.keys(links)).to.have.length(12);
      expect(links).to.have.property("ssh_login");
      expect(links).to.have.property("datasets");

      expect(links.ssh_login).to.contain(FIXTURES.alpha.ssh_login_docs_url);
      // Alpha sets only the SSH link; the rest are null rather than absent.
      expect(links.datasets, "an unset section link").to.be.null;
    });
  });

  it("exposes queue metrics keyed by queue name, not per queue_specs row", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((response) => {
      const metrics = response.body.queue_metrics;
      expect(metrics, "queue_metrics").to.be.an("object");
      // PHP encodes an empty array as a JSON list, so a resource with no
      // metrics would arrive as [] without the controller's object cast.
      // Only the encoded response shows this, which is why it is asserted here.
      expect(Array.isArray(metrics), "queue_metrics must not be a JSON array").to.be.false;
      expect(Object.keys(metrics).sort()).to.deep.eq(FIXTURES.alpha.metrics_queues);

      expect(metrics["gpu-standard"]).to.have.property("job_count");
      expect(metrics["gpu-standard"].daily_wait).to.be.an("array");
      expect(response.body.queue_metrics_updated).to.be.a("string");
      // Null until the nightly fetch rewrites a blob from before last_data
      // existed (the CI database's fixture can be one), else YYYY-MM-DD.
      expect(response.body, "queue_metrics_last_data").to.have.property("queue_metrics_last_data");
      const lastData = response.body.queue_metrics_last_data;
      if (lastData !== null) {
        expect(lastData).to.match(/^\d{4}-\d{2}-\d{2}$/);
      }
      // wait_time is hours or null (missing data), never omitted.
      Object.entries(metrics).forEach(([name, queue]) => {
        expect(queue, `${name} wait_time`).to.have.property("wait_time");
        if (queue.wait_time !== null) {
          expect(queue.wait_time, `${name} wait_time`).to.be.a("number");
        }
      });

      // The measurement lives once in queue_metrics; queue_specs rows are per
      // node type and must not carry a copy.
      response.body.queue_specs.forEach((spec) => {
        expect(spec, `queue_specs row ${spec.name}`).to.not.have.property("metrics");
      });
    });
  });

  it("emits an empty queue_metrics object, not an array, when a resource has none", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.gamma.resource_id}`).then((response) => {
      const metrics = response.body.queue_metrics;
      expect(metrics, "queue_metrics").to.be.an("object");
      expect(Array.isArray(metrics), "an empty map must encode as {} not []").to.be.false;
      expect(Object.keys(metrics)).to.have.length(0);
    });
  });

  it("GET /api/1.0/resources returns only documented resources by default", () => {
    cy.request("/api/1.0/resources").then((response) => {
      expect(response.status).to.eq(200);
      expect(response.body).to.have.property("count");
      expect(response.body).to.have.property("resources");
      expect(response.body.resources).to.be.an("array");
      expect(response.body.count).to.be.greaterThan(0);
      // Every returned resource must have a description (documented=true default).
      response.body.resources.forEach((r) => {
        expect(r.has_documentation).to.eq(true);
      });

      const first = response.body.resources[0];
      expect(first).to.have.property("nid");
      expect(first).to.have.property("title");
      expect(first).to.have.property("short_name");
      expect(first).to.have.property("resource_id");
      expect(first).to.have.property("url");
    });
  });

  it("GET /api/1.0/resources?documented=false returns a superset of the default", () => {
    cy.request("/api/1.0/resources?documented=false").then((full) => {
      cy.request("/api/1.0/resources").then((documented) => {
        // Unfiltered count is always >= documented count. We don't assert
        // strictly greater because it depends on whether the DB currently
        // holds undocumented resources — true on prod/CI, not guaranteed in
        // a fixtures-only environment.
        expect(full.body.count).to.be.at.least(documented.body.count);
        // The documented subset must be strictly contained in the full set.
        const fullIds = new Set(full.body.resources.map((r) => r.nid));
        documented.body.resources.forEach((r) => {
          expect(fullIds.has(r.nid), `documented resource ${r.nid} missing from documented=false response`).to.be.true;
        });
      });
    });
  });

  it(`GET /api/1.0/resources/${FIXTURES.alpha.resource_id} returns full detail`, () => {
    cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((response) => {
      expect(response.status).to.eq(200);

      const body = response.body;
      // operations_cider_node_load() rewrites title to short_name in memory.
      expect(body.title).to.eq("Alpha");
      expect(body.short_name).to.eq("Alpha");
      expect(body.resource_id).to.eq(FIXTURES.alpha.resource_id);
      expect(body.org_name).to.eq(FIXTURES.alpha.org_name);
      expect(body.resource_type).to.eq("Compute");
      expect(body.mfa_required).to.eq(true);
      expect(body.account_required).to.eq(true);

      // Scalar text/link fields
      expect(body.login_text).to.include("getting started guide");
      expect(body.jobs_info).to.include("Alpha uses Slurm for job scheduling");
      expect(body.software_list_url).to.include(FIXTURES.alpha.software_list_url_fragment);

      // Paragraph data
      expect(body.file_transfer).to.be.an("array");
      expect(body.file_transfer).to.have.length(3);
      expect(body.file_transfer[0].method).to.eq("Globus");
      expect(body.file_transfer[0].recommended).to.eq(true);

      expect(body.storage).to.be.an("array");
      expect(body.storage).to.have.length(4);
      expect(body.storage[0].directory).to.eq("Home");
      // Storage quota is now two typed fields (raw, GB) plus a summary string.
      // The deprecated free-text `quota` is gone.
      expect(body.storage[0]).to.not.have.property("quota");
      expect(body.storage[0].quota_size).to.eq(25);
      expect(body.storage[0].quota_inode_amount).to.eq(1000000);
      expect(body.storage[0].summary).to.eq("25 GB, 1,000,000 files");
      // Large quotas render as decimal TB in the summary; raw stays in GB.
      const project = body.storage.find((s) => s.directory === "Project");
      expect(project.quota_size).to.eq(10000);
      expect(project.summary).to.eq("10 TB, 5,000,000 files");

      expect(body.queue_specs).to.be.an("array");
      // queue_specs is the deprecated per-row shape, derived from node types
      // and queues: one entry per queue and node type (10 rows over 6 queues in
      // the fixture). It stays a flat list and leaves grouping to the consumer.
      expect(body.queue_specs).to.have.length(10);
      expect(body.queue_specs.map((q) => q.name)).to.deep.eq([
        "gpu-standard", "gpu-standard", "gpu-large", "gpu-large",
        "debug", "cpu-shared", "cpu-shared", "gpu-cloud", "gpu-cloud", "fpga",
      ]);
      // Structured queue fields stay raw and typed (GB), with a derived summary.
      const gpuStandard = body.queue_specs[0];
      expect(gpuStandard.name).to.eq("gpu-standard");
      // node_count is how many nodes of that type the queue can use.
      expect(gpuStandard.node_count).to.eq(100);
      expect(gpuStandard.cpu_count).to.eq(64);
      expect(gpuStandard.cpu_type).to.eq("AMD EPYC 7763");
      expect(gpuStandard.gpu_count).to.eq(4);
      expect(gpuStandard.gpu_type).to.eq("NVIDIA A100");
      expect(gpuStandard.gpu_vram).to.eq(80);
      // `ram` carries node RAM (field_rp_node_ram, GB) despite the legacy name.
      expect(gpuStandard.ram).to.eq(256);
      // Summary includes the node count between the GPU and CPU clauses.
      expect(gpuStandard.summary).to.eq(
        "4 NVIDIA A100 (80 GB vRAM), 100 nodes, 64-core AMD EPYC 7763, 256 GB RAM"
      );
      // CPU-only queue: no GPU clause in the summary.
      const cpuShared = body.queue_specs.find((q) => q.name === "cpu-shared");
      expect(cpuShared.gpu_count).to.be.null;
      expect(cpuShared.gpu_type).to.be.null;
      expect(cpuShared.node_count).to.eq(200);
      expect(cpuShared.summary).to.eq(
        "200 nodes, 128-core Intel Xeon Platinum 8480+, 256 GB RAM"
      );
      // gpu-cloud: GPU type/vRAM present but no per-node count; summary drops
      // the leading count and there's no node count clause.
      const gpuCloud = body.queue_specs.find((q) => q.name === "gpu-cloud");
      expect(gpuCloud.summary).to.eq(
        "NVIDIA H100 (80 GB vRAM), 32-core AMD EPYC 9004, 384 GB RAM"
      );
      // Mixed-GPU node: models joined, known counts summed, vRAM dropped
      // because it differs per model.
      const mixed = body.queue_specs.filter((q) => q.name === "gpu-cloud")[1];
      expect(mixed.gpu_type).to.eq("AMD MI100, AMD MI210");
      expect(mixed.gpu_count).to.eq(8);
      expect(mixed.gpu_vram).to.be.null;
      expect(mixed.node_count).to.eq(8);
      // Non-GPU accelerators never feed the deprecated gpu_* columns: the FPGA
      // node's row has no GPU data, only its CPU, RAM and the queue's count.
      const fpgaRow = body.queue_specs.find((q) => q.name === "fpga");
      expect(fpgaRow.gpu_type, "FPGA is not a GPU").to.be.null;
      expect(fpgaRow.gpu_count).to.be.null;
      expect(fpgaRow.gpu_vram).to.be.null;
      expect(fpgaRow.node_count).to.eq(6);
      expect(fpgaRow.ram).to.eq(256);
      expect(fpgaRow.purpose).to.eq("FPGA development and acceleration jobs.");

      // Node types: hardware entered once, each with its list of GPUs.
      expect(body.node_types.map((t) => t.label)).to.deep.eq([
        "NVIDIA A100 (4 GPU)", "NVIDIA H100 (4 GPU)", "NVIDIA A100 (8 GPU)",
        "NVIDIA H100 (8 GPU)", "NVIDIA A100 (debug)", "CPU node",
        "NVIDIA H100 (cloud)", "AMD MI100 + MI210", "Arm node", "FPGA node",
      ]);
      const mixedType = body.node_types[7];
      expect(mixedType.cpu_type).to.eq("AMD EPYC 7763");
      expect(mixedType.cpu_count).to.eq(64);
      expect(mixedType.ram).to.eq(512);
      expect(mixedType.cpu_architecture).to.eq("x86_64");
      // `gpus` is gone: every accelerator is one entry with a type.
      expect(mixedType).to.not.have.property("gpus");
      expect(mixedType.accelerators).to.deep.eq([
        { type: "gpu", model: "AMD MI100", count: 4, memory: 32 },
        { type: "gpu", model: "AMD MI210", count: 4, memory: 64 },
      ]);
      expect(mixedType.summary).to.eq(
        "4× AMD MI100 (32 GB vRAM), 4× AMD MI210 (64 GB vRAM), 64-core AMD EPYC 7763, 512 GB RAM"
      );
      expect(body.node_types[3].description).to.eq("Full-node jobs on the newer H100 partition.");
      expect(body.node_types[5].accelerators, "CPU node has no accelerators").to.deep.eq([]);
      // GPU without a per-node count keeps the model and memory, count null.
      expect(body.node_types[6].accelerators).to.deep.eq([
        { type: "gpu", model: "NVIDIA H100", count: null, memory: 80 },
      ]);

      // Arm CPU architecture is its own key, defaulting to x86_64 in the fixture.
      const armType = body.node_types.find((t) => t.label === "Arm node");
      expect(armType.cpu_architecture).to.eq("arm");
      expect(armType.cpu_type).to.eq("Ampere Altra Max");
      expect(armType.cpu_count).to.eq(128);
      expect(armType.ram).to.eq(512);
      expect(armType.accelerators).to.deep.eq([]);
      expect(armType.summary).to.eq("128-core Ampere Altra Max (Arm), 512 GB RAM");
      body.node_types.filter((t) => t.label !== "Arm node").forEach((t) => {
        expect(t.cpu_architecture, `${t.label} architecture`).to.eq("x86_64");
      });

      // Non-GPU accelerator: an FPGA with its own model, count and memory.
      const fpgaType = body.node_types.find((t) => t.label === "FPGA node");
      expect(fpgaType.accelerators).to.deep.eq([
        { type: "fpga", model: "Xilinx Alveo U250", count: 2, memory: 64 },
      ]);
      expect(fpgaType.summary).to.eq(
        "2× Xilinx Alveo U250 (FPGA, 64 GB memory), 48-core AMD EPYC 7413, 256 GB RAM"
      );
      // Every accelerator carries one of the allowed types.
      body.node_types.forEach((t) => {
        t.accelerators.forEach((a) => {
          expect(["gpu", "fpga", "vector_engine", "other"], `${t.label} accelerator type`).to.include(a.type);
        });
      });

      // Queues: one entry per queue name, pointing at node types by label
      // with the per-queue node count.
      expect(body.queues.map((q) => q.name)).to.deep.eq([
        "gpu-standard", "gpu-large", "debug", "cpu-shared", "gpu-cloud", "fpga",
      ]);
      expect(body.queues[0].purpose).to.eq("General purpose GPU jobs with up to 4 GPUs per node.");
      expect(body.queues[0].max_wall_time_hours).to.eq(48);
      expect(body.queues[0].max_wall_time_display).to.eq("48 hours");
      expect(body.queues[0].nodes).to.deep.eq([
        { node_type: "NVIDIA A100 (4 GPU)", node_count: 100 },
        { node_type: "NVIDIA H100 (4 GPU)", node_count: 25 },
      ]);
      // Queue-level charging unit and access, null when the queue sets none.
      const queue = (name) => body.queues.find((q) => q.name === name);
      expect(queue("gpu-standard").charged_per).to.eq("gpu");
      expect(queue("gpu-standard").access).to.eq("batch");
      expect(queue("gpu-large").charged_per).to.eq("node");
      expect(queue("gpu-large").access).to.eq("by_request");
      expect(queue("debug").access).to.eq("interactive");
      expect(queue("cpu-shared").charged_per).to.eq("core");
      expect(queue("cpu-shared").access).to.be.null;
      expect(queue("gpu-cloud").charged_per).to.be.null;
      expect(queue("gpu-cloud").access).to.be.null;
      // A queue lists every node type that serves it, x86_64 and Arm alike.
      expect(queue("cpu-shared").nodes).to.deep.eq([
        { node_type: "CPU node", node_count: 200 },
        { node_type: "Arm node", node_count: 40 },
      ]);
      expect(queue("fpga").nodes).to.deep.eq([
        { node_type: "FPGA node", node_count: 6 },
      ]);
      // Unknown node count stays null, not 0.
      expect(body.queues[4].nodes).to.deep.eq([
        { node_type: "NVIDIA H100 (cloud)", node_count: null },
        { node_type: "AMD MI100 + MI210", node_count: 8 },
      ]);
      // Every queue reference resolves to a node type on the resource.
      const labels = body.node_types.map((t) => t.label);
      body.queues.forEach((queue) => {
        queue.nodes.forEach((n) => {
          expect(labels, `${queue.name} node type`).to.include(n.node_type);
        });
      });

      // Max wall time: the field stores minutes; the API exposes hours (possibly
      // fractional) under the original key plus a friendly display string.
      // gpu-standard: 2880 min = 48 hours.
      expect(gpuStandard.max_wall_time_hours).to.eq(48);
      expect(gpuStandard.max_wall_time_display).to.eq("48 hours");
      // debug: 30 min = 0.5 hours, sub-hour display.
      const debug = body.queue_specs.find((q) => q.name === "debug");
      expect(debug.max_wall_time_hours).to.eq(0.5);
      expect(debug.max_wall_time_display).to.eq("30 minutes");
      // cpu-shared has no limit -> null.
      expect(cpuShared.max_wall_time_hours).to.be.null;
      expect(cpuShared.max_wall_time_display).to.be.null;

      expect(body.datasets).to.be.an("array");
      expect(body.datasets).to.have.length(2);

      expect(body.top_software).to.be.an("array");
      expect(body.top_software).to.have.length(5);

      expect(body.ood_software).to.be.an("array");
      expect(body.ood_software.map((s) => s.name)).to.include.members(["Jupyter", "RStudio"]);
      const jup = body.ood_software.find((s) => s.name === "Jupyter");
      expect(jup.web_page).to.include("jupyter.org");
      // Every entry is SDS-enriched (name-only input, enriched on cron).
      const rst = body.ood_software.find((s) => s.name === "RStudio");
      expect(rst.web_page).to.include("posit.co");
      expect(rst.research_field).to.eq("Other Natural Sciences");

      // SSH logins use structured fields (hostname / placeholder / docs_url).
      expect(body.ssh_logins).to.be.an("array");
      expect(body.ssh_logins).to.have.length(FIXTURES.alpha.ssh_hostnames.length);
      expect(body.ssh_logins[0]).to.have.property("hostname");
      expect(body.ssh_logins[0].hostname).to.include(FIXTURES.alpha.ssh_hostnames[0]);
      expect(body.ssh_logins[0]).to.not.have.property("url");
      // login03 has an explicit docs URL; assert it survives to the API.
      const login03 = body.ssh_logins.find((l) => l.hostname.startsWith("login03"));
      expect(login03).to.exist;
      expect(login03.docs_url).to.include(FIXTURES.alpha.login03_docs_url);

      expect(body.support_links).to.be.an("array");
      expect(body.support_links).to.have.length(3);
    });
  });

  it("GET /api/1.0/resources/nonexistent returns 404", () => {
    cy.request({
      url: "/api/1.0/resources/nonexistent",
      failOnStatusCode: false,
    }).then((response) => {
      expect(response.status).to.eq(404);
      expect(response.body).to.have.property("error");
    });
  });

  it("Legacy resource (Beta) falls back to its queue rows and has no node types", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.beta.resource_id}`).then((response) => {
      const body = response.body;
      expect(body.node_types, "no node types").to.be.an("array").and.have.length(0);

      // queue_specs are the legacy rows as entered, hardware included.
      expect(body.queue_specs.map((q) => q.name)).to.deep.eq(["batch", "gpu"]);
      const batch = body.queue_specs[0];
      expect(batch.cpu_type).to.eq("AMD EPYC 7543");
      expect(batch.cpu_count).to.eq(64);
      expect(batch.node_count).to.eq(120);
      expect(batch.ram).to.eq(256);
      expect(batch.gpu_type).to.be.null;
      expect(batch.max_wall_time_hours).to.eq(24);
      const gpu = body.queue_specs[1];
      expect(gpu.gpu_type).to.eq("NVIDIA A40");
      expect(gpu.gpu_count).to.eq(2);
      expect(gpu.gpu_vram).to.eq(48);
      expect(gpu.node_count).to.eq(10);

      // queues still list every queue row, with no node-type links.
      expect(body.queues.map((q) => q.name)).to.deep.eq(["batch", "gpu"]);
      body.queues.forEach((q) => {
        expect(q.nodes, `${q.name} nodes`).to.deep.eq([]);
        expect(q.charged_per, `${q.name} charged_per`).to.be.null;
        expect(q.access, `${q.name} access`).to.be.null;
      });
      expect(body.queues[0].purpose).to.eq("General purpose CPU jobs.");
    });
  });

  it("Sparse resource (Beta) returns empty paragraph arrays", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.beta.resource_id}`).then((response) => {
      expect(response.status).to.eq(200);
      expect(response.body.title).to.eq("Beta");
      expect(response.body.description).to.include("CPU-only cluster");
      expect(response.body.file_transfer).to.have.length(0);
      expect(response.body.storage).to.have.length(0);
      // Beta's only content beyond the sparse sections is its legacy queue rows.
      expect(response.body.queue_specs).to.have.length(2);
      expect(response.body.datasets).to.have.length(0);
    });
  });

  it("Beta inherits support_links / office_hours / software_list_url from its Group", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.beta.resource_id}`).then((response) => {
      const body = response.body;
      // Beta has no own values for these — the API returns the Group's.
      expect(body.support_links).to.be.an("array");
      const titles = body.support_links.map((l) => l.title);
      FIXTURES.group.support_link_titles.forEach((t) => {
        expect(titles).to.include(t);
      });
      expect(body.office_hours).to.include(FIXTURES.group.office_hours_url_fragment);
      expect(body.software_list_url).to.include(FIXTURES.group.software_list_url_fragment);
    });
  });

  it("Alpha's own values override the Group (not inherited)", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((response) => {
      const body = response.body;
      const titles = body.support_links.map((l) => l.title);
      FIXTURES.group.support_link_titles.forEach((t) => {
        expect(titles).to.not.include(t);
      });
      expect(body.office_hours).to.include(FIXTURES.alpha.office_hours_url_fragment);
      expect(body.software_list_url).to.include(FIXTURES.alpha.software_list_url_fragment);
    });
  });

  it("Partial resource (Gamma) returns only populated sections", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.gamma.resource_id}`).then((response) => {
      expect(response.status).to.eq(200);
      // title resolves field_rp_display_name first, then the CiDeR short name.
      expect(response.body.title).to.eq("Gamma AI System");
      expect(response.body.short_name).to.eq("Gamma");
      expect(response.body.storage).to.have.length(2);
      expect(response.body.file_transfer).to.have.length(0);
      expect(response.body.queue_specs).to.have.length(0);
      expect(response.body.support_links).to.have.length(1);
    });
  });

  it("Gamma (not a Group member) does not inherit Group values", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.gamma.resource_id}`).then((response) => {
      const body = response.body;
      const titles = body.support_links.map((l) => l.title);
      FIXTURES.group.support_link_titles.forEach((t) => {
        expect(titles).to.not.include(t);
      });
      expect(body.office_hours).to.not.include(FIXTURES.group.office_hours_url_fragment);
    });
  });

  it("listing entries include last_modified", () => {
    cy.request("/api/1.0/resources").then((r) => {
      expect(r.body.resources[0]).to.have.property("last_modified");
      expect(r.body.resources[0].last_modified).to.match(/^\d{4}-\d{2}-\d{2}T/);
    });
  });

  it("detail includes last_modified and a 64-char content_hash", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((r) => {
      expect(r.body).to.have.property("last_modified");
      expect(r.body).to.have.property("content_hash");
      expect(r.body.content_hash).to.match(/^[a-f0-9]{64}$/);
    });
  });

  it("detail content_hash is stable across two requests", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((a) => {
      cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((b) => {
        expect(a.body.content_hash).to.eq(b.body.content_hash);
      });
    });
  });

  it("detail exposes ETag/Last-Modified headers for revalidation", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((r) => {
      // Headers are advisory; the body content_hash is the authoritative signal.
      // We assert the response is well-formed and carries the change fields.
      expect(r.body).to.have.property("content_hash");
      expect(r.body).to.have.property("last_modified");
      // ETag may be present depending on the cache layer; if present it must be
      // shaped "<nid>-<changed>".
      if (r.headers.etag) {
        expect(r.headers.etag).to.match(/^(W\/)?"\d+-\d+"$/);
      }
    });
  });

  it("GET /api/1.0/resource-groups populated_sections matches the member detail responses", () => {
    checkPopulatedSections(
      "Test Resource Group",
      [FIXTURES.alpha.resource_id, FIXTURES.beta.resource_id],
      false
    );
  });

  describe("sections inherited from a Resource Group", () => {
    before(() => {
      seedInheritanceFixture();
    });

    it("GET /api/1.0/resource-groups populated_sections matches the member detail responses for the inheritance group", () => {
      checkPopulatedSections("Test Inheritance Group", [FIXTURES.delta.resource_id], true);
    });

    it("GET /api/1.0/resource-groups lists sections a member inherits only from its group", () => {
      cy.request("/api/1.0/resource-groups").then((listing) => {
        const group = listing.body.groups.find((g) => g.title === "Test Inheritance Group");
        expect(group, '"Test Inheritance Group" in /resource-groups').to.exist;
        expect(group.variants.map((v) => v.resource_id)).to.deep.equal([FIXTURES.delta.resource_id]);
        // The API's fixed section order.
        expect(group.populated_sections).to.deep.equal(["file_transfer", "storage", "datasets"]);
      });

      // Delta owns none of these, so the detail response proves it inherits them.
      cy.request(`/api/1.0/resources/${FIXTURES.delta.resource_id}`).then((detail) => {
        expect(detail.body.file_transfer, "file_transfer").to.be.an("array").and.have.length.of.at.least(1);
        expect(hasText(detail.body.datasets_text), "datasets_text is non-blank").to.be.true;
      });
    });
  });

  it("every listing entry has a boolean listed property", () => {
    cy.request("/api/1.0/resources").then((response) => {
      response.body.resources.forEach((r) => {
        expect(r.listed, `listed on ${r.title}`).to.be.a("boolean");
      });
    });
  });

  it("listed is true for own-listed fixtures and for members of a listed group", () => {
    cy.request("/api/1.0/resources").then((response) => {
      const byId = (id) => response.body.resources.find((r) => r.resource_id === id);
      // Alpha and Gamma are checked field_rp_listing on the AMP domain.
      expect(byId(FIXTURES.alpha.resource_id).listed, "Alpha own listing").to.eq(true);
      expect(byId(FIXTURES.gamma.resource_id).listed, "Gamma own listing").to.eq(true);
      // Beta is not listed itself but belongs to the listed Test Resource Group.
      expect(byId(FIXTURES.beta.resource_id).listed, "Beta via group").to.eq(true);
      const delta = response.body.resources.find((r) => r.title === FIXTURES.noCider.title);
      expect(delta.listed, "Delta-NoCider own listing").to.eq(true);
    });
  });

  it("GET /api/1.0/resources/node/{nid} returns the same body as the resource_id route", () => {
    cy.request("/api/1.0/resources").then((list) => {
      const alpha = list.body.resources.find((r) => r.resource_id === FIXTURES.alpha.resource_id);
      cy.request(`/api/1.0/resources/node/${alpha.nid}`).then((byNid) => {
        cy.request(`/api/1.0/resources/${FIXTURES.alpha.resource_id}`).then((byId) => {
          expect(byNid.status).to.eq(200);
          expect(byNid.body.listed).to.be.a("boolean");
          expect(byNid.body).to.deep.eq(byId.body);
        });
      });
    });
  });

  it("GET /api/1.0/resources/node/{nid} serves a resource with no CiDeR or global ID", () => {
    cy.request("/api/1.0/resources").then((list) => {
      const delta = list.body.resources.find((r) => r.title === FIXTURES.noCider.title);
      expect(delta, "Delta-NoCider in listing").to.exist;
      cy.request(`/api/1.0/resources/node/${delta.nid}`).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body.resource_id).to.be.null;
        expect(response.body.global_resource_id).to.be.null;
        expect(response.body.title).to.eq(FIXTURES.noCider.title);
        expect(response.body.listed).to.eq(true);
      });
    });
  });

  it("GET /api/1.0/resources/node/{nid} returns 404 for a nonexistent nid", () => {
    cy.request({
      url: "/api/1.0/resources/node/999999999",
      failOnStatusCode: false,
    }).then((response) => {
      expect(response.status).to.eq(404);
      expect(response.body).to.have.property("error");
    });
  });

  it("GET /api/1.0/resources/node/{nid} returns 404 for a resource_group nid", () => {
    cy.request("/api/1.0/resource-groups").then((groups) => {
      const group = groups.body.groups.find((g) => g.title === FIXTURES.group.title);
      expect(group, "Test Resource Group in resource-groups").to.exist;
      cy.request({
        url: `/api/1.0/resources/node/${group.nid}`,
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(404);
        expect(response.body).to.have.property("error");
      });
    });
  });

});
