/**
 * DataForm create/edit flag screen.
 */
import {
  useState,
  useEffect,
  useRef,
  forwardRef,
  useImperativeHandle,
} from "@wordpress/element";
import {
  Button,
  TextControl,
  TextareaControl,
  ToggleControl,
  SelectControl,
  RangeControl,
  Flex,
  FlexItem,
  Notice,
  Spinner,
  Card,
  CardBody,
  CardHeader,
} from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import {
  fetchFlag,
  createFlag,
  updateFlag,
  updateEnvState,
  fetchGroups,
  createGroup,
  createTarget,
  updateTarget,
  deleteTarget,
} from "./api";

const {
  flagKey: initialFlagKey,
  listUrl,
  currentEnv,
} = window.myrkAdminFlags ?? {};
const env = currentEnv ?? "production";

const REWIND_STRATEGIES = [
  {
    label: __("Stepwise (safe default)", "myrk-feature-flags"),
    value: "stepwise",
  },
  {
    label: __("Immediate (instant rollback)", "myrk-feature-flags"),
    value: "immediate",
  },
];

const ANON_STRATEGIES = [
  { label: __("IP address", "myrk-feature-flags"), value: "ip" },
  { label: __("Session ID", "myrk-feature-flags"), value: "session" },
  { label: __("Device fingerprint", "myrk-feature-flags"), value: "device" },
];

const LIFECYCLE_OPTIONS = [
  {
    label: __("Temporary — stale-eligible", "myrk-feature-flags"),
    value: "temporary",
  },
  {
    label: __(
      "Permanent — excluded from stale detection",
      "myrk-feature-flags",
    ),
    value: "permanent",
  },
];

const defaultForm = {
  flag_key: "",
  label: "",
  description: "",
  default_state: false,
  rewind_strategy: "stepwise",
  anonymous_strategy: "ip",
  lifecycle: "temporary",
  group_id: null,
  tags: "",
  env_enabled: false,
  env_percentage: 0,
};

export function EditScreen() {
  const flagKey = initialFlagKey ?? "";
  const isEditing = Boolean(flagKey);

  const [form, setForm] = useState(defaultForm);
  const [keyWasEdited, setKeyWasEdited] = useState(false);
  const [loading, setLoading] = useState(isEditing);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);
  const [errors, setErrors] = useState({});
  const [groups, setGroups] = useState([]);
  const [targets, setTargets] = useState([]);
  const targetingRef = useRef(null);
  const [showNewGroup, setShowNewGroup] = useState(false);
  const [newGroupName, setNewGroupName] = useState("");
  const [creatingGroup, setCreatingGroup] = useState(false);

  useEffect(() => {
    fetchGroups()
      .then(setGroups)
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (!isEditing) {
      return;
    }

    fetchFlag(flagKey)
      .then((data) => {
        const envState = data.environments?.[env];
        setForm({
          flag_key: data.flag_key,
          label: data.label,
          description: data.description ?? "",
          default_state: data.default ?? false,
          rewind_strategy: data.rewind_strategy ?? "stepwise",
          anonymous_strategy: "ip",
          lifecycle: data.lifecycle ?? "temporary",
          group_id: data.group_id ? Number(data.group_id) : null,
          tags: data.tags ?? "",
          env_enabled: envState?.status === "enabled",
          env_percentage: envState?.percentage ?? 0,
        });
        setTargets(data.targets?.[env] ?? []);
        setLoading(false);
      })
      .catch((err) => {
        setNotice({
          type: "error",
          message:
            err?.message ?? __("Could not load flag.", "myrk-feature-flags"),
        });
        setLoading(false);
      });
  }, [flagKey, isEditing]);

  const update = (key) => (value) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const handleLabelChange = (value) => {
    setForm((prev) => {
      const next = { ...prev, label: value };
      if (!isEditing && !keyWasEdited) {
        next.flag_key = labelToKey(value);
      }
      return next;
    });
  };

  const handleKeyChange = (value) => {
    setKeyWasEdited(true);
    setForm((prev) => ({ ...prev, flag_key: value }));
  };

  const validate = () => {
    const errs = {};
    if (!form.flag_key.match(/^[a-z][a-z0-9_]*$/)) {
      errs.flag_key = __(
        "Must start with a lowercase letter and contain only lowercase letters, digits, and underscores.",
        "myrk-feature-flags",
      );
    }
    if (!form.label.trim()) {
      errs.label = __("Label is required.", "myrk-feature-flags");
    }
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleCreateGroup = async () => {
    if (!newGroupName.trim()) {
      return;
    }
    setCreatingGroup(true);
    try {
      const created = await createGroup({ name: newGroupName.trim() });
      setGroups((prev) => [...prev, created]);
      update("group_id")(created.id);
      setNewGroupName("");
      setShowNewGroup(false);
    } catch (err) {
      setNotice({
        type: "error",
        message:
          err?.message ?? __("Failed to create group.", "myrk-feature-flags"),
      });
    } finally {
      setCreatingGroup(false);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validate()) {
      return;
    }

    setSaving(true);

    try {
      if (targetingRef.current) {
        await targetingRef.current.saveIfPending();
      }

      let resolvedFlagKey = flagKey;

      const definitionFields = {
        label: form.label,
        description: form.description,
        default_state: form.default_state,
        rewind_strategy: form.rewind_strategy,
        lifecycle: form.lifecycle,
        group_id: form.group_id, // null = explicitly remove group
        tags: form.tags || undefined,
      };

      if (isEditing) {
        await updateFlag(flagKey, definitionFields);
      } else {
        const created = await createFlag({
          flag_key: form.flag_key,
          ...definitionFields,
        });
        resolvedFlagKey = created.flag_key ?? form.flag_key;
      }

      await updateEnvState(resolvedFlagKey, env, {
        status: form.env_enabled ? "enabled" : "disabled",
        percentage: form.env_percentage,
      });

      window.location.href = listUrl ?? "admin.php?page=myrk";
    } catch (err) {
      const message = err?.message ?? __("Save failed.", "myrk-feature-flags");
      setNotice({ type: "error", message });
      setSaving(false);
    }
  };

  const registerSnippet =
    `use Myrk\\Myrk; // add once to the top of your file\n\n` +
    `Myrk::register( '${form.flag_key || "my_flag"}', [\n` +
    `\t'label' => '${form.label || "My Flag"}',\n` +
    `] );`;

  // -------------------------------------------------------------------------
  // Render
  // -------------------------------------------------------------------------

  if (loading) {
    return (
      <div className="myrk-screen">
        <Spinner />
      </div>
    );
  }

  const title = isEditing
    ? /* translators: %s: flag key */ sprintf(
        __("Edit Flag: %s", "myrk-feature-flags"),
        flagKey,
      )
    : __("Add New Flag", "myrk-feature-flags");

  const groupOptions = [
    { label: __("— No group —", "myrk-feature-flags"), value: "" },
    ...groups.map((g) => ({ label: g.name, value: String(g.id) })),
  ];

  return (
    <div className="myrk-screen myrk-edit-screen">
      {notice && (
        <Notice
          status={notice.type}
          onRemove={() => setNotice(null)}
          isDismissible
        >
          {notice.message}
        </Notice>
      )}
      <div className="myrk-brand-mark">
        <span className="myrk-brand-mark__rune">ᛗ</span>
        <span className="myrk-brand-mark__wordmark">myrk</span>
      </div>
      <h1 className="wp-heading-inline">{title}</h1>{" "}
      <a href={listUrl} className="page-title-action">
        {__("← All flags", "myrk-feature-flags")}
      </a>
      <hr className="wp-header-end" />
      <form onSubmit={handleSubmit}>
        <div className="myrk-edit-screen__layout">
          <div className="myrk-edit-screen__main">
            {/* Flag Definition */}
            <Card>
              <CardHeader>
                <strong>{__("Flag Definition", "myrk-feature-flags")}</strong>
              </CardHeader>
              <CardBody>
                <div className="myrk-field-stack">
                  <div className="myrk-field-group">
                    <TextControl
                      label={__("Label", "myrk-feature-flags")}
                      value={form.label}
                      onChange={handleLabelChange}
                      help={__(
                        "A short, human-readable name shown in this admin screen.",
                        "myrk-feature-flags",
                      )}
                      className={errors.label ? "myrk-field--error" : ""}
                      __nextHasNoMarginBottom
                    />
                    {errors.label && (
                      <p className="myrk-field__error">{errors.label}</p>
                    )}
                  </div>

                  <div className="myrk-field-group">
                    <TextControl
                      label={__("Flag Key", "myrk-feature-flags")}
                      value={form.flag_key}
                      onChange={handleKeyChange}
                      readOnly={isEditing}
                      help={
                        isEditing
                          ? __(
                              "Flag key cannot be changed after creation.",
                              "myrk-feature-flags",
                            )
                          : __(
                              "Auto-generated from label — edit to override. Lowercase letters, digits, and underscores only.",
                              "myrk-feature-flags",
                            )
                      }
                      className={errors.flag_key ? "myrk-field--error" : ""}
                      __nextHasNoMarginBottom
                    />
                    {errors.flag_key && (
                      <p className="myrk-field__error">{errors.flag_key}</p>
                    )}
                  </div>

                  {!isEditing && (
                    <div className="myrk-register-hint">
                      <p className="myrk-register-hint__label">
                        {__(
                          "Register this flag in code before saving:",
                          "myrk-feature-flags",
                        )}
                      </p>
                      <pre className="myrk-register-hint__code">
                        {registerSnippet}
                      </pre>
                    </div>
                  )}

                  <TextareaControl
                    label={__("Description", "myrk-feature-flags")}
                    value={form.description}
                    onChange={update("description")}
                    help={__(
                      "Helps your team remember what this flag controls and when it's safe to remove.",
                      "myrk-feature-flags",
                    )}
                    rows={3}
                    __nextHasNoMarginBottom
                  />
                </div>
              </CardBody>
            </Card>

            {/* Organization */}
            <Card>
              <CardHeader>
                <strong>{__("Organization", "myrk-feature-flags")}</strong>
              </CardHeader>
              <CardBody>
                <div className="myrk-field-stack">
                  <div>
                    <SelectControl
                      label={__("Group", "myrk-feature-flags")}
                      value={form.group_id ? String(form.group_id) : ""}
                      options={groupOptions}
                      onChange={(val) =>
                        update("group_id")(val ? Number(val) : null)
                      }
                      help={__(
                        "Organize related flags by sprint, release, or initiative.",
                        "myrk-feature-flags",
                      )}
                      __nextHasNoMarginBottom
                    />
                    {!showNewGroup ? (
                      <Button
                        variant="link"
                        onClick={() => setShowNewGroup(true)}
                        style={{
                          marginTop: "6px",
                          fontSize: "12px",
                        }}
                      >
                        {__("+ New group", "myrk-feature-flags")}
                      </Button>
                    ) : (
                      <div className="myrk-inline-create">
                        <TextControl
                          label={__("Group name", "myrk-feature-flags")}
                          value={newGroupName}
                          onChange={setNewGroupName}
                          placeholder={__(
                            "e.g. Sprint 42",
                            "myrk-feature-flags",
                          )}
                          __nextHasNoMarginBottom
                        />
                        <Button
                          variant="secondary"
                          onClick={handleCreateGroup}
                          isBusy={creatingGroup}
                          disabled={creatingGroup || !newGroupName.trim()}
                        >
                          {__("Create", "myrk-feature-flags")}
                        </Button>
                        <Button
                          variant="tertiary"
                          onClick={() => {
                            setShowNewGroup(false);
                            setNewGroupName("");
                          }}
                          disabled={creatingGroup}
                        >
                          {__("Cancel", "myrk-feature-flags")}
                        </Button>
                      </div>
                    )}
                  </div>

                  <TextControl
                    label={__("Tags", "myrk-feature-flags")}
                    value={form.tags}
                    onChange={update("tags")}
                    placeholder={__(
                      "payments, checkout, v2-redesign",
                      "myrk-feature-flags",
                    )}
                    help={__(
                      "Comma-separated. Used for filtering in the flags list.",
                      "myrk-feature-flags",
                    )}
                    __nextHasNoMarginBottom
                  />
                </div>
              </CardBody>
            </Card>

            {/* Behavior */}
            <Card>
              <CardHeader>
                <strong>{__("Behavior", "myrk-feature-flags")}</strong>
              </CardHeader>
              <CardBody>
                <div className="myrk-field-stack">
                  <SelectControl
                    label={__("Lifecycle", "myrk-feature-flags")}
                    value={form.lifecycle}
                    options={LIFECYCLE_OPTIONS}
                    onChange={update("lifecycle")}
                    help={__(
                      "Permanent flags are excluded from stale detection.",
                      "myrk-feature-flags",
                    )}
                    __nextHasNoMarginBottom
                  />

                  <ToggleControl
                    label={__("Default state", "myrk-feature-flags")}
                    help={__(
                      "Returned when the flag has no environment state or the circuit breaker is tripped.",
                      "myrk-feature-flags",
                    )}
                    checked={form.default_state}
                    onChange={update("default_state")}
                    __nextHasNoMarginBottom
                  />

                  <SelectControl
                    label={__("Rewind strategy", "myrk-feature-flags")}
                    value={form.rewind_strategy}
                    options={REWIND_STRATEGIES}
                    onChange={update("rewind_strategy")}
                    help={__(
                      "How the circuit breaker rolls the flag back.",
                      "myrk-feature-flags",
                    )}
                    __nextHasNoMarginBottom
                  />

                  <SelectControl
                    label={__(
                      "Anonymous identifier strategy",
                      "myrk-feature-flags",
                    )}
                    value={form.anonymous_strategy}
                    options={ANON_STRATEGIES}
                    onChange={update("anonymous_strategy")}
                    help={__(
                      "Used for percentage rollout when no logged-in user is available.",
                      "myrk-feature-flags",
                    )}
                    __nextHasNoMarginBottom
                  />
                </div>
              </CardBody>
            </Card>

            {isEditing && (
              <TargetingCard
                ref={targetingRef}
                flagKey={flagKey}
                targets={targets}
                onTargetsChange={setTargets}
              />
            )}
          </div>

          <div className="myrk-edit-screen__sidebar">
            <Card>
              <CardHeader>
                <strong>{__("Rollout", "myrk-feature-flags")}</strong>
              </CardHeader>
              <CardBody>
                <ToggleControl
                  label={__("Enabled", "myrk-feature-flags")}
                  help={sprintf(
                    /* translators: %s: environment name */
                    __("Status in the %s environment.", "myrk-feature-flags"),
                    env,
                  )}
                  checked={form.env_enabled}
                  onChange={update("env_enabled")}
                  __nextHasNoMarginBottom
                />
                <RangeControl
                  label={__("Rollout percentage", "myrk-feature-flags")}
                  value={form.env_percentage}
                  onChange={update("env_percentage")}
                  min={0}
                  max={100}
                  step={1}
                  help={__(
                    "Percentage of users who see this flag as enabled.",
                    "myrk-feature-flags",
                  )}
                  __nextHasNoMarginBottom
                />
              </CardBody>
            </Card>

            <CodeCard flagKey={form.flag_key} />

            <Flex
              className="myrk-edit-screen__actions"
              direction="column"
              gap={2}
            >
              <FlexItem>
                <Button
                  variant="primary"
                  type="submit"
                  isBusy={saving}
                  disabled={saving}
                  style={{
                    width: "100%",
                    justifyContent: "center",
                  }}
                >
                  {isEditing
                    ? __("Update Flag", "myrk-feature-flags")
                    : __("Create Flag", "myrk-feature-flags")}
                </Button>
              </FlexItem>
              <FlexItem>
                <Button variant="tertiary" href={listUrl} disabled={saving}>
                  {__("Cancel", "myrk-feature-flags")}
                </Button>
              </FlexItem>
            </Flex>
          </div>
        </div>
      </form>
    </div>
  );
}

// -------------------------------------------------------------------------
// Sub-components
// -------------------------------------------------------------------------

const TARGET_TYPES = [
  { label: __("Role", "myrk-feature-flags"), value: "role" },
  { label: __("Capability", "myrk-feature-flags"), value: "capability" },
  { label: __("User ID", "myrk-feature-flags"), value: "user_id" },
  { label: __("Email domain", "myrk-feature-flags"), value: "email_domain" },
];

const TARGET_OPERATORS = [
  { label: __("is", "myrk-feature-flags"), value: "equals" },
  { label: __("is not", "myrk-feature-flags"), value: "not_equals" },
  { label: __("contains", "myrk-feature-flags"), value: "contains" },
  { label: __("is in list", "myrk-feature-flags"), value: "in_list" },
];

const ROLE_OPTIONS = [
  { label: __("— Select role —", "myrk-feature-flags"), value: "" },
  { label: __("Administrator", "myrk-feature-flags"), value: "administrator" },
  { label: __("Editor", "myrk-feature-flags"), value: "editor" },
  { label: __("Author", "myrk-feature-flags"), value: "author" },
  { label: __("Contributor", "myrk-feature-flags"), value: "contributor" },
  { label: __("Subscriber", "myrk-feature-flags"), value: "subscriber" },
];

const CAPABILITY_OPTIONS = [
  { label: __("— Select capability —", "myrk-feature-flags"), value: "" },
  { label: "manage_options", value: "manage_options" },
  { label: "edit_posts", value: "edit_posts" },
  { label: "edit_pages", value: "edit_pages" },
  { label: "publish_posts", value: "publish_posts" },
  { label: "publish_pages", value: "publish_pages" },
  { label: "edit_others_posts", value: "edit_others_posts" },
  { label: "delete_posts", value: "delete_posts" },
  { label: "upload_files", value: "upload_files" },
  { label: "moderate_comments", value: "moderate_comments" },
  { label: "manage_categories", value: "manage_categories" },
  { label: "edit_users", value: "edit_users" },
  { label: "create_users", value: "create_users" },
  { label: "delete_users", value: "delete_users" },
  { label: "activate_plugins", value: "activate_plugins" },
  { label: "install_plugins", value: "install_plugins" },
  { label: "update_plugins", value: "update_plugins" },
  { label: "switch_themes", value: "switch_themes" },
  { label: "export", value: "export" },
  { label: "import", value: "import" },
  { label: "unfiltered_html", value: "unfiltered_html" },
];

const TARGET_TYPE_LABELS = {
  role: __("Role", "myrk-feature-flags"),
  capability: __("Capability", "myrk-feature-flags"),
  user_id: __("User ID", "myrk-feature-flags"),
  email_domain: __("Email domain", "myrk-feature-flags"),
};

const TARGET_OP_LABELS = {
  equals: __("is", "myrk-feature-flags"),
  not_equals: __("is not", "myrk-feature-flags"),
  contains: __("contains", "myrk-feature-flags"),
  in_list: __("is in list", "myrk-feature-flags"),
};

const defaultNewTarget = { type: "role", operator: "equals", value: "" };

const TargetingCard = forwardRef(function TargetingCard(
  { flagKey, targets, onTargetsChange },
  ref,
) {
  const [showForm, setShowForm] = useState(false);
  const [newTarget, setNewTarget] = useState(defaultNewTarget);
  const [error, setError] = useState(null);

  useImperativeHandle(ref, () => ({
    async saveIfPending() {
      if (showForm && newTarget.value.trim()) {
        await handleAdd();
      }
    },
  }));

  const updateNew = (key) => (value) =>
    setNewTarget((prev) => ({
      ...prev,
      [key]: value,
      ...(key === "type" ? { value: "" } : {}),
    }));

  const renderValueControl = () => {
    if (newTarget.type === "role" && newTarget.operator !== "in_list") {
      return (
        <SelectControl
          label={__("Value", "myrk-feature-flags")}
          value={newTarget.value}
          options={ROLE_OPTIONS}
          onChange={updateNew("value")}
          __nextHasNoMarginBottom
        />
      );
    }
    if (newTarget.type === "capability" && newTarget.operator !== "in_list") {
      return (
        <SelectControl
          label={__("Value", "myrk-feature-flags")}
          value={newTarget.value}
          options={CAPABILITY_OPTIONS}
          onChange={updateNew("value")}
          __nextHasNoMarginBottom
        />
      );
    }
    const placeholders = {
      user_id: __("e.g. 123 or 123,456 for in list", "myrk-feature-flags"),
      email_domain: __("e.g. acme.com", "myrk-feature-flags"),
      role: __("e.g. administrator,editor", "myrk-feature-flags"),
      capability: __("e.g. edit_posts,publish_posts", "myrk-feature-flags"),
    };
    return (
      <TextControl
        label={__("Value", "myrk-feature-flags")}
        value={newTarget.value}
        onChange={updateNew("value")}
        placeholder={placeholders[newTarget.type] ?? ""}
        help={
          newTarget.operator === "in_list"
            ? __("Comma-separated list of values.", "myrk-feature-flags")
            : undefined
        }
        __nextHasNoMarginBottom
      />
    );
  };

  const handleAdd = async () => {
    if (!newTarget.value.trim()) {
      setError(__("Please select or enter a value.", "myrk-feature-flags"));
      return;
    }
    setError(null);
    try {
      const created = await createTarget(flagKey, {
        env,
        ...newTarget,
        value: newTarget.value.trim(),
      });
      onTargetsChange((prev) => [...prev, created]);
      setNewTarget(defaultNewTarget);
      setShowForm(false);
    } catch (err) {
      setError(err?.message ?? __("Failed to add rule.", "myrk-feature-flags"));
    }
  };

  const handleToggle = async (target) => {
    try {
      const updated = await updateTarget(flagKey, target.id, {
        enabled: !target.enabled,
      });
      onTargetsChange((prev) =>
        prev.map((t) => (t.id === target.id ? updated : t)),
      );
    } catch (err) {
      setError(
        err?.message ?? __("Failed to update rule.", "myrk-feature-flags"),
      );
    }
  };

  const handleDelete = async (target) => {
    try {
      await deleteTarget(flagKey, target.id);
      onTargetsChange((prev) => prev.filter((t) => t.id !== target.id));
    } catch (err) {
      setError(
        err?.message ?? __("Failed to delete rule.", "myrk-feature-flags"),
      );
    }
  };

  return (
    <Card>
      <CardHeader>
        <strong>{__("Targeting", "myrk-feature-flags")}</strong>
      </CardHeader>
      <CardBody>
        {error && (
          <Notice status="error" onRemove={() => setError(null)} isDismissible>
            {error}
          </Notice>
        )}
        <p className="myrk-targeting-help">
          {__(
            "Rules force the flag on or off for specific users regardless of the rollout percentage.",
            "myrk-feature-flags",
          )}
        </p>
        {targets.length > 0 && (
          <div className="myrk-target-list">
            {targets.map((t) => (
              <div
                key={t.id}
                className={`myrk-target-row${
                  t.enabled ? "" : " myrk-target-row--disabled"
                }`}
              >
                <span className="myrk-target-row__rule">
                  <span className="myrk-target-row__type">
                    {TARGET_TYPE_LABELS[t.type] ?? t.type}
                  </span>
                  <span className="myrk-target-row__op">
                    {TARGET_OP_LABELS[t.operator] ?? t.operator}
                  </span>
                  <span className="myrk-target-row__value">{t.value}</span>
                </span>
                <span className="myrk-target-row__actions">
                  <ToggleControl
                    checked={t.enabled}
                    onChange={() => handleToggle(t)}
                    __nextHasNoMarginBottom
                  />
                  <Button
                    variant="tertiary"
                    isDestructive
                    onClick={() => handleDelete(t)}
                    size="small"
                  >
                    {__("Remove", "myrk-feature-flags")}
                  </Button>
                </span>
              </div>
            ))}
          </div>
        )}
        {showForm && (
          <div className="myrk-target-form">
            <SelectControl
              label={__("Type", "myrk-feature-flags")}
              value={newTarget.type}
              options={TARGET_TYPES}
              onChange={updateNew("type")}
              __nextHasNoMarginBottom
            />
            <SelectControl
              label={__("Operator", "myrk-feature-flags")}
              value={newTarget.operator}
              options={TARGET_OPERATORS}
              onChange={updateNew("operator")}
              __nextHasNoMarginBottom
            />
            {renderValueControl()}
            <p className="myrk-targeting-autosave-hint">
              {__(
                "Rule will be saved when you click Update Flag.",
                "myrk-feature-flags",
              )}
            </p>
            <Button
              variant="link"
              onClick={() => {
                setShowForm(false);
                setNewTarget(defaultNewTarget);
                setError(null);
              }}
              style={{ fontSize: "12px" }}
            >
              {__("Discard", "myrk-feature-flags")}
            </Button>
          </div>
        )}
        <Button
          variant="link"
          onClick={() => {
            setShowForm(true);
            setNewTarget(defaultNewTarget);
          }}
          className="myrk-add-target-btn"
        >
          {__("+ Add targeting rule", "myrk-feature-flags")}
        </Button>
      </CardBody>
    </Card>
  );
});

function CodeCard({ flagKey }) {
  const key = flagKey || "my_flag";

  const phpSnippet = [
    `if ( myrk_is_enabled( '${key}' ) ) {`,
    "    // your feature code",
    "}",
  ].join("\n");

  const jsSnippet = [
    `if ( myrkIsEnabled( '${key}' ) ) {`,
    "    // your feature code",
    "}",
  ].join("\n");

  return (
    <Card>
      <CardHeader>
        <strong>{__("Use in code", "myrk-feature-flags")}</strong>
      </CardHeader>
      <CardBody>
        <div className="myrk-field-stack" style={{ gap: "12px" }}>
          <div>
            <p className="myrk-code-label">PHP</p>
            <pre className="myrk-code-snippet">{phpSnippet}</pre>
          </div>
          <div>
            <p className="myrk-code-label">JS</p>
            <pre className="myrk-code-snippet">{jsSnippet}</pre>
          </div>
        </div>
      </CardBody>
    </Card>
  );
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function sprintf(fmt, ...args) {
  return fmt.replace(/%s/g, () => args.shift());
}

function labelToKey(label) {
  return label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^[^a-z]+/, "")
    .replace(/_+$/, "");
}
