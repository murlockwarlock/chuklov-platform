<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

type Stage = 'contacts' | 'profile' | 'service' | 'goals';

type Profile = {
    full_name: string | null;
    email: string | null;
    phone: string | null;
    lead_source: string | null;
};

type Service = {
    id: number;
    name: string;
    summary: string;
};

type LegalDocument = {
    id: number;
    purpose: string;
    content: string;
    isRequired: boolean;
};

type OnboardingForm = {
    full_name: string;
    email: string;
    phone: string;
    lead_source: string;
    consents: { legal_document_id: number; granted: boolean }[];
};

const props = defineProps<{
    currentStage: Stage;
    stages: Stage[];
    profile: Profile;
    verifiedFields: string[];
    completed: boolean;
    askLeadSource: boolean;
    legalDocuments: LegalDocument[];
    services: Service[];
}>();

const labels: Record<Stage, string> = {
    contacts: 'Контакты',
    profile: 'Профиль',
    service: 'Услуги',
    goals: 'Документы',
};

const form = useForm<OnboardingForm>({
    full_name: props.profile.full_name ?? '',
    email: props.profile.email ?? '',
    phone: props.profile.phone ?? '',
    lead_source: props.profile.lead_source ?? '',
    consents: [],
});

const consentState = reactive(Object.fromEntries(
    props.legalDocuments.map((document) => [document.id, false]),
) as Record<number, boolean>);

const currentStageIndex = computed(() => props.stages.indexOf(props.currentStage));

const documentLabels: Record<string, string> = {
    privacy: 'Политика конфиденциальности',
    terms: 'Условия использования',
    consent: 'Согласие на обработку данных',
};

function fieldError(field: keyof OnboardingForm): string | undefined {
    return form.errors[field];
}

function generalError(field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[field];
}

function documentLabel(purpose: string): string {
    return documentLabels[purpose] ?? 'Документ';
}

function submitStage(): void {
    if (props.currentStage === 'goals') {
        form.consents = props.legalDocuments.map((document) => ({
            legal_document_id: document.id,
            granted: consentState[document.id] ?? false,
        }));
    }

    form.transform((data) => {
        if (props.currentStage === 'contacts') {
            return props.askLeadSource
                ? {
                    full_name: data.full_name,
                    email: data.email,
                    phone: data.phone,
                    lead_source: data.lead_source,
                }
                : {
                    full_name: data.full_name,
                    email: data.email,
                    phone: data.phone,
                };
        }

        return props.currentStage === 'goals' ? { consents: data.consents } : {};
    }).post(`/portal/onboarding/${props.currentStage}`, {
        preserveScroll: true,
    });
}
</script>

<template>
  <Head title="Настройка профиля" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            Личный кабинет
          </p>
          <h1 class="portal-heading portal-heading--page">
            Настройка профиля
          </h1>
        </div>
        <Link
          href="/"
          class="portal-button portal-button--secondary"
        >
          В личный кабинет
        </Link>
      </header>

      <ol
        class="portal-stage-list"
        aria-label="Этапы настройки профиля"
      >
        <li
          v-for="(stage, index) in props.stages"
          :key="stage"
          class="portal-stage"
          :class="{
            'portal-stage--complete': index < currentStageIndex,
            'portal-stage--current': index === currentStageIndex,
          }"
        >
          <p class="portal-stage__number">
            {{ index + 1 }}
          </p>
          <p class="portal-stage__label">
            {{ labels[stage] }}
          </p>
        </li>
      </ol>

      <section
        class="portal-panel portal-stack portal-stack--loose"
        aria-labelledby="stage-heading"
      >
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            Текущий шаг
          </p>
          <h2
            id="stage-heading"
            class="portal-heading portal-heading--section"
          >
            {{ labels[props.currentStage] }}
          </h2>
        </div>

        <form
          v-if="props.currentStage === 'contacts'"
          class="portal-grid portal-grid--form"
          @submit.prevent="submitStage"
        >
          <div class="portal-field portal-field--wide">
            <label
              for="full_name"
              class="portal-label"
            >Имя и фамилия</label>
            <input
              id="full_name"
              v-model="form.full_name"
              required
              autocomplete="name"
              class="portal-input"
            >
            <p
              v-if="fieldError('full_name')"
              class="portal-error"
            >
              {{ fieldError('full_name') }}
            </p>
          </div>

          <div class="portal-field">
            <label
              for="email"
              class="portal-label"
            >Email
              <span
                v-if="props.verifiedFields.includes('email')"
                class="portal-muted"
              >Подтверждён</span>
            </label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              autocomplete="email"
              :disabled="props.verifiedFields.includes('email')"
              class="portal-input"
            >
            <p
              v-if="fieldError('email')"
              class="portal-error"
            >
              {{ fieldError('email') }}
            </p>
          </div>

          <div class="portal-field">
            <label
              for="phone"
              class="portal-label"
            >Телефон</label>
            <input
              id="phone"
              v-model="form.phone"
              type="tel"
              autocomplete="tel"
              class="portal-input"
            >
            <p
              v-if="fieldError('phone')"
              class="portal-error"
            >
              {{ fieldError('phone') }}
            </p>
          </div>

          <div
            v-if="props.askLeadSource"
            class="portal-field portal-field--wide"
          >
            <label
              for="lead_source"
              class="portal-label"
            >Как вы узнали о нас?</label>
            <input
              id="lead_source"
              v-model="form.lead_source"
              class="portal-input"
            >
            <p
              v-if="fieldError('lead_source')"
              class="portal-error"
            >
              {{ fieldError('lead_source') }}
            </p>
          </div>

          <div class="portal-form-actions">
            <button
              type="submit"
              :disabled="form.processing"
              class="portal-button portal-button--primary"
            >
              {{ form.processing ? 'Сохраняем…' : 'Сохранить и продолжить' }}
            </button>
          </div>
        </form>

        <form
          v-else-if="props.currentStage === 'profile'"
          class="portal-stack"
          @submit.prevent="submitStage"
        >
          <p class="portal-copy">
            Профиль готов. Перейдите дальше, чтобы посмотреть доступные услуги.
          </p>
          <p
            v-if="generalError('stage')"
            class="portal-error"
            role="alert"
          >
            {{ generalError('stage') }}
          </p>
          <button
            type="submit"
            :disabled="form.processing"
            class="portal-button portal-button--primary self-start"
          >
            {{ form.processing ? 'Сохраняем…' : 'Продолжить' }}
          </button>
        </form>

        <form
          v-else-if="props.currentStage === 'service'"
          class="portal-stack"
          @submit.prevent="submitStage"
        >
          <div
            v-if="props.services.length"
            class="portal-service-grid"
          >
            <article
              v-for="service in props.services"
              :key="service.id"
              class="portal-service-card"
            >
              <h3 class="portal-heading portal-heading--card">
                {{ service.name }}
              </h3>
              <p class="portal-card__summary">
                {{ service.summary }}
              </p>
            </article>
          </div>
          <p
            v-else
            class="portal-empty"
          >
            Услуги пока не добавлены.
          </p>
          <button
            type="submit"
            :disabled="form.processing"
            class="portal-button portal-button--primary self-start"
          >
            {{ form.processing ? 'Сохраняем…' : 'Продолжить' }}
          </button>
        </form>

        <div
          v-else
          class="portal-stack"
        >
          <div
            v-if="props.completed"
            class="portal-notice"
            role="status"
          >
            Профиль настроен. Теперь можно пользоваться личным кабинетом.
          </div>
          <form
            v-else
            class="portal-stack"
            @submit.prevent="submitStage"
          >
            <div
              v-if="props.legalDocuments.length"
              class="portal-stack portal-stack--tight"
            >
              <article
                v-for="document in props.legalDocuments"
                :key="document.id"
                class="portal-service-card"
              >
                <div class="portal-stack portal-stack--tight">
                  <h3 class="portal-heading portal-heading--card">
                    {{ documentLabel(document.purpose) }}
                  </h3>
                  <div class="portal-legal-content">
                    {{ document.content }}
                  </div>
                  <label class="portal-confirm">
                    <input
                      v-model="consentState[document.id]"
                      type="checkbox"
                      class="portal-checkbox"
                    >
                    <span>{{ document.isRequired ? 'Принимаю обязательный документ.' : 'Принимаю документ.' }}</span>
                  </label>
                </div>
              </article>
            </div>
            <p
              v-else
              class="portal-empty"
            >
              Документы пока не добавлены.
            </p>
            <p
              v-if="generalError('consents')"
              class="portal-error"
              role="alert"
            >
              {{ generalError('consents') }}
            </p>
            <button
              type="submit"
              :disabled="form.processing"
              class="portal-button portal-button--primary self-start"
            >
              {{ form.processing ? 'Сохраняем…' : 'Завершить настройку' }}
            </button>
          </form>
        </div>
      </section>
    </section>
  </main>
</template>
