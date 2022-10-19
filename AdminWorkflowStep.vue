<template>
	<div
		class="card mb-5 workflow-card"
		:class="!step.is_valid ? 'border-danger' : ''"
	>
		<div class="loading" v-if="loading"></div>

		<div class="card-body" v-else>
			<div class="d-flex justify-content-between align-items-center">
				<h5 class="mb-0" :class="step.triggered_by">
					<i class="text-success ti-check-box mr-2" v-if="step.is_valid"></i>
					<i class="text-danger ti-alert mr-2" v-else></i>
					Step {{ stepIndex + 1 }}: {{ step.title }}
					<span
						class="ml-1 text-capitalize"
						:class="
							step.triggered_by == 'admin' ? 'text-warning' : 'text-success'
						"
						>({{ step.triggered_by }})</span
					>
				</h5>
				<div>
					<button class="btn btn-info" @click="visible = !visible">
						<template v-if="visible">Collapse Step</template>
						<template v-if="!visible">Edit Step</template>
					</button>
					<b-dropdown text="Actions">
						<b-dropdown-item @click="editSettings">
							Edit Settings
						</b-dropdown-item>

						<b-dropdown-item @click="destroy">
							<span class="text-danger">
								Delete this step
							</span>
						</b-dropdown-item>
					</b-dropdown>
				</div>
			</div>

			<b-collapse :visible="visible" class="pt-3">
				<b-form-group label="Who completes this step?">
					<b-form-select
						id="step-triggered-by"
						v-model="step.triggered_by"
						@change="update"
					>
						<option value="investor"> The Investor</option>
						<option value="admin"> The Admin</option>
					</b-form-select>
				</b-form-group>

				<template v-if="step.triggered_by">
					<b-form-group
						:label="`What action do you want the ${step.triggered_by} to take?`"
					>
						<b-form-select v-model="step.type" @change="update">
							<option value="form"> Fill out a form</option>
							<option value="sign"> Sign a document</option>
							<option value="wiring_instructions"> Wiring Instructions</option>
							<option value="verify"> Verify Accreditation</option>
							<option value="other">
								Other Instructions
							</option>
						</b-form-select>
					</b-form-group>

					<template v-if="step.type">
						<template v-if="step.type == 'other'">
							<admin-workflow-step-instructions :step="step" @update="update">
							</admin-workflow-step-instructions>
						</template>

						<template v-if="step.type == 'sign'">
							<admin-workflow-step-sign
								:step="step"
								:stepIndex="stepIndex"
								:offering="offering"
								@update="update"
							>
							</admin-workflow-step-sign>
						</template>

						<template v-if="step.type == 'wiring_instructions'">
							<admin-wiring-instructions-step
								:step="step"
								:stepIndex="stepIndex"
								:offering="offering"
								@update="update"
							>
							</admin-wiring-instructions-step>
						</template>

						<template v-if="step.type == 'verify'">
							<admin-workflow-step-verify
								:step="step"
								:stepIndex="stepIndex"
								:offering="offering"
								@update="update"
							>
							</admin-workflow-step-verify>
						</template>

						<template v-if="step.type == 'form'">
							<admin-workflow-step-form
								:step="step"
								:workflow="workflow"
								@addField="$emit('addField', $event)"
								@editField="$emit('editField', $event)"
								@update="update(false)"
								@get="$emit('get')"
							>
							</admin-workflow-step-form>
						</template>
						<admin-workflow-actions
							ref="actionsModal"
							@get="$emit('getWorkflow')"
							:workflow="workflow"
							:offering="offering"
							:step="step"
						>
						</admin-workflow-actions>
					</template>
				</template>

				<!-- Modals -->
				<edit-step-field
					ref="editFieldModal"
					:step="step"
					@updated="update"
					:field="selectedField"
					@get="$emit('get')"
				>
				</edit-step-field>
			</b-collapse>

			<edit-step-modal
				ref="editStepModal"
				:workflow="workflow"
				:step="step"
				@update="update"
			></edit-step-modal>
		</div>
	</div>
</template>

<script>
import adminMixins from "../../../adminMixins";
import formMixins from "../../../formMixins";

export default {
	mixins: [adminMixins, formMixins],
	props: ["step", "offering", "workflow", "stepIndex", "leads"],
	data() {
		return {
			loading: false,
			selectedField: {},
			visible: false,
		};
	},
	mounted() {
		this.visible = !this.step.is_valid;
	},
	methods: {
		editSettings() {
			this.$refs.editStepModal.show();
		},
		editField(field = {}) {
			let self = this;
			this.selectedField = field;
			this.$nextTick().then(() => {
				self.$refs.editFieldModal.show();
			});
		},
		moveUp() {
			this.step.index--;
			this.moveStep();
		},
		moveDown() {
			this.step.index++;
			this.moveStep();
		},
		moveStep() {
			this.loading = true;
			axios
				.patch(`/api/admin/steps/${this.step.id}/move`, this.step)
				.then((response) => {
					this.loading = false;
					this.handleResponse(response);
					this.$emit("getWorkflow");
				})
				.catch((error) => {
					this.loading = false;
					this.handleResponse(error);
				});
		},
		update(loading = true) {
			this.loading = loading;
			axios
				.patch(`/api/admin/steps/${this.step.id}`, this.step)
				.then((response) => {
					this.loading = false;
					this.handleResponse(response);
					this.$emit("getWorkflow");
				})
				.catch((error) => {
					this.loading = false;
					this.handleResponse(error);
				});
		},
		destroy() {
			if (this.stepInUse()) {
				this.$bvModal.msgBoxOk(
					"Some investors are currently on this workflow step. All investors on this step must be removed before this step can be removed."
				);
				return;
			}
			this.$bvModal
				.msgBoxConfirm(["Are you sure you want to remove this step?"])
				.then((success) => {
					if (!success) {
						return false;
					}

					this.loading = true;
					axios
						.delete(`/api/admin/steps/${this.step.id}`)
						.then((response) => {
							this.loading = false;
							this.handleResponse(response);
							this.$emit("getWorkflow");
						})
						.catch((error) => {
							this.loading = false;
							this.handleResponse(error);
						});
				});
		},
		stepInUse() {
			return this.leads.find((lead) => lead.workflow_step_id == this.step.id);
		},
	},
	computed: {
		user() {
			return this.$root.user;
		},
		nextStepInvisible() {
			let nextStep = this.workflow.steps[this.stepIndex + 1];
			// If there isn't another step or if the next step is triggered by an admin
			// this is invisible
			if (!nextStep || nextStep.triggered_by === "admin") {
				return true;
			}
			return false;
		},
	},
};
</script>

<style>
h5.admin {
	opacity: 0.6;
}
</style>
