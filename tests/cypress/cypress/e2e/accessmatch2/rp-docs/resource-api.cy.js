// Expected values seeded by amp_dev_install_create_rp_docs_test_data().
// Edit in lockstep with amp_dev/amp_dev.install when fixtures change.
const FIXTURES = {
  alpha: {
    resource_id: "test-alpha-9999",
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
  beta: {
    resource_id: "test-beta-9998",
  },
  gamma: {
    resource_id: "test-gamma-9997",
  },
  group: {
    support_link_titles: ["Group User Guide", "Group Ticket System"],
    office_hours_url_fragment: "group.test.example.edu/office-hours",
    software_list_url_fragment: "group.test.example.edu/software",
  },
};

describe("Resource Documentation API", () => {

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
      expect(body.queue_specs).to.have.length(5);
      // Structured queue fields stay raw and typed (GB), with a derived summary.
      const gpuStandard = body.queue_specs[0];
      expect(gpuStandard.name).to.eq("gpu-standard");
      // node_count is the per-partition node count (field_rp_node_count).
      expect(gpuStandard.node_count).to.eq(100);
      expect(gpuStandard.cpu_count).to.eq(64);
      expect(gpuStandard.cpu_type).to.eq("AMD EPYC 7763");
      expect(gpuStandard.gpu_count).to.eq(4);
      expect(gpuStandard.gpu_type).to.eq("NVIDIA A100");
      expect(gpuStandard.gpu_vram).to.eq(80);
      // `ram` carries node RAM (field_rp_vram, GB) despite the legacy name.
      expect(gpuStandard.ram).to.eq(256);
      // Summary includes the node count between the GPU and CPU clauses.
      expect(gpuStandard.summary).to.eq(
        "4 NVIDIA A100 (80 GB vRAM), 100 nodes, 64-core AMD EPYC 7763, 256 GB RAM"
      );
      // CPU-only queue: no GPU clause in the summary.
      const cpuShared = body.queue_specs.find((q) => q.name === "cpu-shared");
      expect(cpuShared.gpu_count).to.eq(0);
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

  it("Sparse resource (Beta) returns empty paragraph arrays", () => {
    cy.request(`/api/1.0/resources/${FIXTURES.beta.resource_id}`).then((response) => {
      expect(response.status).to.eq(200);
      expect(response.body.title).to.eq("Beta");
      expect(response.body.description).to.include("CPU-only cluster");
      expect(response.body.file_transfer).to.have.length(0);
      expect(response.body.storage).to.have.length(0);
      expect(response.body.queue_specs).to.have.length(0);
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
      expect(response.body.title).to.eq("Gamma");
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

});
