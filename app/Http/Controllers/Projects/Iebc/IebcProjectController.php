<?php

namespace App\Http\Controllers\Projects\Iebc;

use App\Actions\Projects\Iebc\GenerateLetterAction;
use App\Actions\Projects\PayProjectAction;
use App\Actions\Services\GetServicePriceAction;
use App\Actions\Services\PayServiceAction;
use App\Enums\DocumentStatus;
use App\Enums\MeteoEnum;
use App\Enums\ProjectAddonType;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ServiceTypes;
use App\Enums\ShipmentProfileSaveType;
use App\Enums\ShipmentProfileStatus;
use App\Enums\UserPermissionsEnum;
use App\Enums\UserType;
use App\Enums\WetSign;
use App\Events\DocumentUploaded;
use App\Events\PlanCheckCommentAdded;
use App\Events\ProjectCreated;
use App\Events\ProjectCommented;
use App\Events\PzseUserCommentedProject;
use App\Events\PlanCheckFileAdded;
use App\Exceptions\InsufficientBalanceException;
use App\Helpers\BalanceHelper;
use App\Helpers\FilePathHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadPdfRequest;
use App\Http\Requests\Project\Iebc\FirstStepRequest;
use App\Http\Requests\Project\Iebc\FirstStepUpdateRequest;
use App\Http\Requests\Project\Iebc\FourthStepRequest;
use App\Http\Requests\Project\Iebc\ProjectDocumentsRequest;
use App\Http\Requests\Project\Iebc\SecondStepRequest;
use App\Http\Requests\Project\Iebc\ThirdStepRequest;
use App\Http\Requests\Project\Iebc\WetStampRequest;
use App\Http\Requests\Project\StatusChangeRequest;
use App\Models\BuildingDescription;
use App\Models\ExistingRoofLoad;
use App\Models\Project;
use App\Models\ProjectAddon;
use App\Models\ProjectComments;
use App\Models\ProjectDocuments;
use App\Models\ProjectDocumentType;
use App\Models\ProjectIebcMeta;
use App\Models\ProjectShippingInformation;
use App\Models\Service;
use App\Models\ShippingProfile;
use App\Models\Stamp;
use App\Models\SurroundingTerrain;
use App\Services\Lookup\Lookup;
use Carbon\Carbon;
use CountryState;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PDF;
use setasign\Fpdi\Tcpdf\Fpdi;
use ZipArchive;
use App\Events\ClientUploadedDocument;
use App\Events\PzseUploadedDocument;

class IebcProjectController extends Controller
{
    /**
     * @var
     */
    private $states;

    /**
     * ProjectController constructor.
     */
    public function __construct()
    {
        $this->middleware(['auth:web', 'permission:' . UserPermissionsEnum::VIEW_PROJECT], ['only' => 'show']);
        $this->middleware(['auth:web', 'permission:' . UserPermissionsEnum::EDIT_PROJECTS . '|' . UserPermissionsEnum::UPDATE_PROJECTS], [
            'only' => ['edit', 'update', 'updateFirstStep', 'updateSecondStep', 'updateThirdStep', 'finishFromSecondStep', 'finishFromThirdStep', 'finishFromFourthStep']
        ]);
        $this->middleware(['auth:web', 'permission:' . UserPermissionsEnum::CREATE_PROJECTS . '|' . UserPermissionsEnum::EDIT_ASSOC_USERS_PROJECTS], [
            'only' => ['create', 'store', 'storeFirstStep', 'finishFromSecondStep', 'finishFromThirdStep', 'finishFromFourthStep']
        ]);
        $this->middleware(['auth:web', 'permission:' . UserPermissionsEnum::CHANGE_STATUS], ['only' => ['changeStatus']]);
        $this->middleware(['auth:web', 'permission:' . UserPermissionsEnum::REQUEST_WET_STAMP], ['only' => ['storeWetStampRequest', 'updateWetStampRequest']]);
        $this->middleware(['auth:web', 'can_access_project'], ['only' => ['showDocuments', 'storeDocuments', 'downloadDocument', 'addComment']]);
        $this->middleware(['auth:web', 'permission:' . UserPermissionsEnum::DOWNLOAD_LETTER], ['only' => ['downloadPdf']]);

        $states = CountryState::getStates('US');

        Arr::forget($states, ['AA', 'AE', 'AP', 'PR', 'GU', 'UM', 'VI']);

        $this->states = $states;

        parent::__construct();
    }

    /**
     * ATC lookup based on project location.
     *
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function lookupAndStreamPdf(Request $request, $id)
    {
        try {
            $project = Project::with([
                'user',
                'iebcMeta' => function ($query) {
                    $query->with([
                        'buildingDescription',
                        'surroundingTerrain',
                        'roofType',
                    ]);
                }])->findOrFail($id);

            $validator = $this->validateRequest(
                [
                    'building_description_id' => $project->iebcMeta->building_description_id,
                    'surrounding_terrain_id'  => $project->iebcMeta->surrounding_terrain_id,
                    'existing_roof_type_id'   => $project->iebcMeta->existing_roof_type_id,
                ],
                [
                    'risk_category_internal_name' => $project->iebcMeta->buildingDescription->risk_category_internal_name,
                    'exposure_category'           => $project->iebcMeta->surroundingTerrain->exposure_category,
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'error'      => true,
                    'message'    => $validator->errors()->messages(),
                ], 400);
            }

            $lookupService = new Lookup($project);
            $lookupData    = $lookupService->doLookup();
            if (is_array($lookupData)) {
                $responseData = $this->constructJsonResponseData($project, $lookupData);
                return response()->json($responseData);
            }

            return response()->json([
                'message' => $lookupData,
            ], 404);
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Something went wrong, please try again later.',
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Throwable
     */
    public function regeneratePdf(Request $request)
    {
        $projectId = $request->get('project_id');
        $project = $project = Project::with([
            'user',
            'projectAhjData',
            'iebcMeta' => function ($query) {
                $query->with([
                    'buildingDescription',
                    'surroundingTerrain',
                    'roofType',
                ]);
            }])->find($projectId);;

        $ahjData = (new Lookup($project))->checkLocation();
        $projectOverrides = $project->projectAhjData ?: null;

        if ($projectOverrides) {
            if ($projectOverrides->snow) {
                $ahjData['snow'] = $projectOverrides->snow;
            }

            if ($projectOverrides->wind) {
                $ahjData['wind'] = $projectOverrides->wind;
            }
        }

        $responseData = $this->constructJsonResponseData($project, $ahjData);
        $viewData = [
            'project' => $project,
            'atc'     => $ahjData,
            'user'    => Auth::user(),
            'stamp'   => $responseData['stamp']
        ];
        $uniqueId = uniqid();

        (new GenerateLetterAction($project, $viewData, true, $uniqueId, true))->execute();
        $viewData['stamp'] = $responseData['stampWithoutSignature'];
        (new GenerateLetterAction($project, $viewData, true, $uniqueId, false))->execute();

        $project->is_letter_regenerated = 1;
        $project->save();

        session()->flash('success', 'Letter successfully regenerated. You can find it under documents section.');

        return redirect()->route('projects.documents.show', $projectId);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        $authUser                           = Auth::user();
        $buildingDescriptions               = BuildingDescription::orderBy('order')->get();
        $surroundingTerrains                = SurroundingTerrain::all();
        $canSeeRiskCatAndSurroundingTerrain = $authUser->hasAnyRole(UserType::PZSE_ENGINEER, UserType::PZSE_ADMIN);

        return view('projects.iebc.create', [
            'states'                             => $this->states,
            'buildingDescriptions'               => $buildingDescriptions,
            'surroundingTerrains'                => $surroundingTerrains,
            'canSeeRiskCatAndSurroundingTerrain' => $canSeeRiskCatAndSurroundingTerrain
        ]);
    }

    /**
     * Saving project initial data.
     *
     * @param FirstStepRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeFirstStep(FirstStepRequest $request)
    {
        DB::beginTransaction();

        try {
            $user = Auth::user();
            $request->merge([
                'price' => (new GetServicePriceAction(auth()->user(), ServiceTypes::IEBC_LETTER))->execute(),
                'type' => ProjectType::IEBC
            ]);
            $project = $user->projects()->create($request->all());
            $meta = $request->meta;

            ProjectIebcMeta::disableAuditing();

            $project->iebcMeta()->create($meta);

            ProjectIebcMeta::enableAuditing();

            $additionalMessages = [];
            $validator = $this->validateFirstStep($meta);

            if ($validator->fails()) {
                $additionalMessages = $validator->errors()->messages();
            }

            DB::commit();

            return response()->json([
                'data' => [
                    'projectId' => $project->id,
                    'action'    => route('projects.update-first-step', $project->id),
                    'method'    => 'PUT',
                ],
                'message'            => 'Project address info has been saved successfully.',
                'additionalMessages' => $additionalMessages
            ], 201);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while saving project address info.'
            ], 500);
        }
    }

    /**
     * Updating first step data.
     *
     * @param FirstStepUpdateRequest $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateFirstStep(FirstStepUpdateRequest $request, $projectId)
    {
        DB::beginTransaction();

        try {
            $project = Project::find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            $meta = $request->meta;
            $additionalMessages = [];
            $validator = $this->validateFirstStep($meta);

            if (!isset($request->meta['building_description_manual_value'])) {
                $meta = Arr::add($meta, 'building_description_manual_value', null);
            }

            if (!isset($request->meta['surrounding_terrain_manual_value'])) {
                $meta = Arr::add($meta, 'surrounding_terrain_manual_value', null);
            }

            if ($validator->fails()) {
                $additionalMessages = $validator->errors()->messages();
            }

            $project->update($request->all());

            ProjectIebcMeta::where('project_id', $project->id)
                ->firstOrFail()
                ->update($meta);

            DB::commit();

            return response()->json([
                'message' => 'Project address info has been saved successfully.',
                'additionalMessages' => $additionalMessages
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while saving project address info.'
            ], 500);
        }
    }

    /**
     * Validating first Step risk category data.
     *
     * @param $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    private function validateFirstStep($data)
    {
        $buildingDescriptionId = $data['building_description_id'];
        $surroundingTerrainId  = $data['surrounding_terrain_id'];
        $buildingDescription   = BuildingDescription::findOrFail($buildingDescriptionId);
        $surroundingTerrain    = SurroundingTerrain::findOrFail($surroundingTerrainId);

        $messages = [
            'building_description_id.should_refer' => $this->getErrorMessage('risk_cat', ['[riskCat]'], [$buildingDescription->risk_category_internal_name]),
            'surrounding_terrain_id.should_refer'  => $this->getErrorMessage('exposure', ['[exposure]'], [$surroundingTerrain->exposure_category]),
        ];

        $validator = Validator::make(
            [
                'building_description_id' => $buildingDescriptionId,
                'surrounding_terrain_id'  => $surroundingTerrainId,
            ],
            [
                'building_description_id' => 'should_refer:' . BuildingDescription::class,
                'surrounding_terrain_id'  => 'should_refer:' . SurroundingTerrain::class,
            ],
            $messages
        );

        return $validator;
    }

    /**
     * Updating second step data.
     *
     * @param SecondStepRequest $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSecondStep(SecondStepRequest $request, $projectId)
    {
        DB::beginTransaction();

        try {
            $project = Project::find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            $meta = $request->meta;
            $additionalMessages = [];
            $validator = $this->validateSecondStep($meta);

            unset($meta['wet_sign_requested']);

            if (!isset($meta['existing_roof_type_manual_value'])) {
                $meta = Arr::add($meta, 'existing_roof_type_manual_value', null);
            }

            if ($validator->fails()) {
                $additionalMessages = $validator->errors()->messages();
            }

            $project->update($request->all());

            ProjectIebcMeta::where('project_id', $project->id)
                ->firstOrFail()
                ->update($meta);

            # create new entry for meteo if not exists in db
            $lookupData = $this->lookup($projectId);
            $newMeteo     = new Lookup($project);
            $getMeteoData = $newMeteo->checkLocation();

            if(!($getMeteoData['state'] == $project->state
                && $getMeteoData['county'] == $project->county
                && $getMeteoData['city'] == $project->city)) {
                $newMeteo->createMeteoInfo($getMeteoData, $lookupData);
            }

            DB::commit();

            return response()->json([
                'message' => 'Project additional info data was saved successfully.',
                'additionalMessages' => $additionalMessages
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while saving project address info.'
            ], 500);
        }
    }

    /**
     * Validating second step roof load data
     *
     * @param $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    private function validateSecondStep($data)
    {
        $existingRoofLoadId = $data['existing_roof_type_id'];
        $messages           = [
            'existing_roof_type_id.should_refer' => $this->getErrorMessage('other'),
        ];

        $validator = Validator::make(
            ['existing_roof_type_id' => $existingRoofLoadId],
            ['existing_roof_type_id' => 'should_refer:' . ExistingRoofLoad::class],
            $messages
        );

        return $validator;
    }

    /**
     * Finishing project from second step.
     *
     * @param SecondStepRequest $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function finishFromSecondStep(SecondStepRequest $request, $projectId)
    {

        DB::beginTransaction();

        try {
            $authUser = Auth::user();
            $meta = $request->meta;
            $project = Project::with([
                'user',
                'iebcMeta' => function ($query) {
                    $query->with([
                        'buildingDescription',
                        'surroundingTerrain',
                        'roofType',
                    ]);
                }])->find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            unset($meta['wet_sign_requested']);

            $wetStamp = ProjectIebcMeta::where('project_id', $project->id)
                ->get();

            if($wetStamp[0]->wet_sign_requested === 1){
                ProjectIebcMeta::where('project_id', $project->id)
                    ->firstOrFail()
                    ->update(['wet_sign_requested' => WetSign::REQUESTED_CANCELLED]);
            }

            if (!isset($meta['existing_roof_type_manual_value'])) {
                $meta = Arr::add($meta, 'existing_roof_type_manual_value', null);
            }

            // Deduct account balance
            if ($project->status === ProjectStatus::PROCESSED
                && !$authUser->hasRole(UserType::PZSE_ENGINEER)) {
                (new PayProjectAction($authUser, $project))->execute();
            }

            $validator = $this->validateRequest(
                [
                    'building_description_id' => $project->iebcMeta->building_description_id,
                    'surrounding_terrain_id'  => $project->iebcMeta->surrounding_terrain_id,
                    'existing_roof_type_id'   => $meta['existing_roof_type_id'],
                ],
                [
                    'risk_category_internal_name' => $project->iebcMeta->buildingDescription->risk_category_internal_name,
                    'exposure_category'           => $project->iebcMeta->surroundingTerrain->exposure_category,
                ]
            );

            if ($validator->fails()) {
                $request->merge(['status' => ProjectStatus::FORWARDED_TO_ENGINEER]);
                $project->fill($request->all());
                $project->save();

                ProjectIebcMeta::where('project_id', $project->id)
                    ->firstOrFail()
                    ->update($meta);

                session()->flash('error', $validator->errors()->getMessages());

                DB::commit();

                return response()->json([
                    'message'    => $validator->errors()->getMessages(),
                    'redirectTo' => route('projects.index')
                ], 404);
            }

            $request->merge(['status' => ProjectStatus::COMPLETED]);

            $project->fill($request->all());
            $project->save();

            ProjectIebcMeta::where('project_id', $project->id)
                ->firstOrFail()
                ->update($meta);

            $lookupData = $this->lookup($projectId);

            # create new entry for meteo if not exists in db
            $newMeteo     = new Lookup($project);
            $getMeteoData = $newMeteo->checkLocation();

            if (!($getMeteoData['state'] == $project->state
                && $getMeteoData['county'] == $project->county
                && $getMeteoData['city'] == $project->city)) {
                $newMeteo->createMeteoInfo($getMeteoData, $lookupData);
            }

            if (is_array($lookupData)) {
                $project      = $project->refresh();
                $responseData = $this->constructJsonResponseData($project, $lookupData);
                $wsOn = false;
                $viewData = [
                    'project' => $project,
                    'atc'     => $lookupData,
                    'user'    => Auth::user(),
                    'stamp'   => $responseData['stamp'],
                    'wsOn'    => $wsOn
                ];
                $uniqueId = uniqid();

                # Forward to engineer for North Carolina projects
                if($project->state == "NC"){
                    $project->status = ProjectStatus::FORWARDED_TO_ENGINEER;
                    $project->save();
                    event(new ProjectCreated($project));

                    DB::commit();

                    session()->flash('error', 'Forwarded to engineer');
                    return response()->json([
                        'redirectTo' => route('projects.index')
                    ], 404);
                }

                (new GenerateLetterAction($project, $viewData, false, $uniqueId, true))->execute();
                $viewData['stamp'] = $responseData['stampWithoutSignature'];
                $viewData['wsOn']  = true;
                (new GenerateLetterAction($project, $viewData, false, $uniqueId, false))->execute();

                event(new ProjectCreated($project));

                DB::commit();
                return response()->json($responseData);
            }

            $project->status = ProjectStatus::FORWARDED_TO_ENGINEER;
            $project->save();

            event(new ProjectCreated($project));

            DB::commit();

            session()->flash('error', $lookupData);
            return response()->json([
                'message'    => $lookupData,
                'redirectTo' => route('projects.index')
            ], 404);
        } catch (Exception $exception) {
            DB::rollBack();

            session()->flash('error', $exception->getMessage());

            return response()->json([
                'message'    => 'Something went wrong while saving project.',
                'redirectTo' => route('projects.edit', $projectId)
            ], 500);
        }
    }

    /**
     * Updating third step data.
     *
     * @param  Request  $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function updateThirdStep(Request $request, $projectId)
    {
        try {
            $project = Project::find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            $lookupData = $this->lookup($projectId);

            # create new entry for meteo if not exists in db
            $newMeteo     = new Lookup($project);
            $getMeteoData = $newMeteo->checkLocation();

            if (!($getMeteoData['state'] == $project->state
                && $getMeteoData['county'] == $project->county
                && $getMeteoData['city'] == $project->city)) {
                $newMeteo->createMeteoInfo($getMeteoData, $lookupData);
            }

            DB::commit();

            return response()->json([
                'message' => 'Done uploading the documents.'
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            session()->flash('error', $exception->getMessage());

            return response()->json([
                'message'    => 'Something went wrong while saving project.',
                'redirectTo' => route('projects.edit', $projectId)
            ], 500);
        }
    }

    /**
     * Finish project from third step.
     *
     * @param ThirdStepRequest $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function finishFromThirdStep(ThirdStepRequest $request, $projectId)
    {
        DB::beginTransaction();

        try {
            $authUser = Auth::user();
            $project = Project::with([
                'user',
                'iebcMeta' => function ($query) {
                    $query->with([
                        'buildingDescription',
                        'surroundingTerrain',
                        'roofType',
                    ]);
                }])->find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            // Deduct account balance
            if ($project->status === ProjectStatus::PROCESSED
                && !$authUser->hasRole(UserType::PZSE_ENGINEER)) {
                (new PayProjectAction($authUser, $project))->execute();
            }

            $hasProjectDocumentPlans = ProjectDocuments::where('project_id', $projectId)
                    ->whereHas('rType', function ($query) {
                        $query->where('id', \App\Enums\ProjectDocumentType::PLANS);
                    })->count() > 0;

            if ($hasProjectDocumentPlans) {
                $project->type = ProjectType::IEBC_STAMPED_PLANS;
                $project-> status = ProjectStatus::FORWARDED_TO_ENGINEER;
            } else {
                $project->type = ProjectType::IEBC;
                $project->status = ProjectStatus::COMPLETED;
            }

            $project->save();

            $lookupData = $this->lookup($projectId);

            if (is_array($lookupData)) {
                $project      = $project->refresh();
                $responseData = $this->constructJsonResponseData($project, $lookupData);
                $viewData = [
                    'project' => $project,
                    'atc'     => $lookupData,
                    'user'    => Auth::user(),
                    'stamp'   => $responseData['stamp']
                ];
                $uniqueId = uniqid();

                # Forward to engineer for North Carolina projects
                if($project->state == "NC"){
                    $project->status = ProjectStatus::FORWARDED_TO_ENGINEER;
                    $project->save();
                    event(new ProjectCreated($project));

                    DB::commit();

                    session()->flash('error', 'Forwarded to engineer');
                    return response()->json([
                        'redirectTo' => route('projects.index')
                    ], 404);
                }

                (new GenerateLetterAction($project, $viewData, false, $uniqueId, true))->execute();
                $viewData['stamp'] = $responseData['stampWithoutSignature'];
                (new GenerateLetterAction($project, $viewData, false, $uniqueId, false))->execute();

                event(new ProjectCreated($project));

                DB::commit();

                return response()->json($responseData);
            }

            $project->status = ProjectStatus::FORWARDED_TO_ENGINEER;
            $project->save();

            event(new ProjectCreated($project));

            DB::commit();

            return response()->json([
                'message'    => 'Project successfully saved.',
                'redirectTo' => route('projects.index')
            ], 404);
        } catch (Exception $exception) {
            DB::rollBack();

            session()->flash('error', $exception->getMessage());

            return response()->json([
                'message'    => 'Something went wrong while saving project.',
                'redirectTo' => route('projects.edit', $projectId)
            ], 500);
        }
    }

    /**
     * Finish project from fourth step.
     *
     * @param FourthStepRequest $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function finishFromFourthStep(FourthStepRequest $request, $projectId)
    {
        DB::beginTransaction();

        try {
            $authUser = Auth::user();
            $shippingProfileData = $request->shipping_profile;
            $project = Project::with([
                'user',
                'iebcMeta' => function ($query) {
                    $query->with([
                        'buildingDescription',
                        'surroundingTerrain',
                        'roofType',
                    ]);
                }])->find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            // Deduct account balance
            if ($project->status === ProjectStatus::PROCESSED
                && !$authUser->hasRole(UserType::PZSE_ENGINEER)) {
                (new PayProjectAction($authUser, $project))->execute();
                (new PayServiceAction($authUser, ServiceTypes::WET_STAMP, $project))->execute();

                ProjectIebcMeta::where('project_id', $project->id)
                    ->update(['wet_sign_requested' => WetSign::REQUESTED]);
            }

            // Creating shipping profile for user
            if ((int) $request->save_shipping_profile === ShipmentProfileStatus::SAVE_REQUESTED) {
                $shippingProfileData['regular'] = ShipmentProfileSaveType::REGULAR;
            }

            $shippingInformation = $authUser->shippingProfiles()->create($shippingProfileData);

            $project->projectShippingProfile()->create([
                'project_id'              => $project->id,
                'shipping_information_id' => $shippingInformation->id,
            ]);

            $hasProjectDocumentPlans = ProjectDocuments::where('project_id', $projectId)
                    ->whereHas('rType', function ($query) {
                        $query->where('id', \App\Enums\ProjectDocumentType::PLANS);
                    })->count() > 0;

            if ($hasProjectDocumentPlans) {
                $project->type = ProjectType::IEBC_STAMPED_PLANS;
                $project-> status = ProjectStatus::FORWARDED_TO_ENGINEER;
            } else {
                $project->type = ProjectType::IEBC;
                $project->status = ProjectStatus::COMPLETED;
            }

            $project->save();

//            if ($authUser->hasAnyRole([UserType::STANDARD_USER, UserType::PRO_CLIENT])) {
//                event(new WetStampRequested($shippingInformation, $project));
//            }

            $validator = $this->validateRequest(
                [
                    'building_description_id' => $project->iebcMeta->building_description_id,
                    'surrounding_terrain_id'  => $project->iebcMeta->surrounding_terrain_id,
                    'existing_roof_type_id'   => $project->iebcMeta->existing_roof_type_id,
                ],
                [
                    'risk_category_internal_name' => $project->iebcMeta->buildingDescription->risk_category_internal_name,
                    'exposure_category'           => $project->iebcMeta->surroundingTerrain->exposure_category,
                ]
            );

            if ($validator->fails()) {
                $project->status = ProjectStatus::FORWARDED_TO_ENGINEER;
                $project->save();

                session()->flash('error', $validator->errors()->getMessages());

                DB::commit();

                return response()->json([
                    'message'    => $validator->errors()->getMessages(),
                    'redirectTo' => route('projects.index')
                ], 404);
            }

            $lookupData = $this->lookup($projectId);

            if (is_array($lookupData)) {
                $project      = $project->refresh();
                $responseData = $this->constructJsonResponseData($project, $lookupData);
                $wsOn = false;
                $viewData = [
                    'project' => $project,
                    'atc'     => $lookupData,
                    'user'    => Auth::user(),
                    'stamp'   => $responseData['stamp'],
                    'wsOn'    => $wsOn
                ];
                $uniqueId = uniqid();

                # Forward to engineer for North Carolina projects
                if($project->state == "NC"){
                    $project->status = ProjectStatus::FORWARDED_TO_ENGINEER;
                    $project->save();
                    event(new ProjectCreated($project));

                    DB::commit();

                    session()->flash('error', 'Forwarded to engineer');
                    return response()->json([
                        'redirectTo' => route('projects.index')
                    ], 404);
                }

                (new GenerateLetterAction($project, $viewData, false, $uniqueId, true))->execute();
                $viewData['stamp'] = $responseData['stampWithoutSignature'];
                $viewData['wsOn'] = true;
                (new GenerateLetterAction($project, $viewData, false, $uniqueId, false))->execute();

                event(new ProjectCreated($project));

                DB::commit();

                return response()->json($responseData);
            }

            $project->status = ProjectStatus::FORWARDED_TO_ENGINEER;
            $project->save();

            event(new ProjectCreated($project));

            DB::commit();

            return response()->json([
                'message'    => 'Project successfully saved.',
                'redirectTo' => route('projects.index')
            ], 404);
        } catch (Exception $exception) {
            DB::rollBack();

            session()->flash('error', $exception->getMessage());

            return response()->json([
                'message'    => 'Something went wrong while saving project.',
                'redirectTo' => route('projects.edit', $projectId)
            ], 500);
        }
    }

    /**
     * Getting stamp file based on state
     * $state - project state
     * $ws - pointing if project requires a wet signed stamp.
     *
     *
     * @used-by ProjectController::store()
     *
     * @param $state
     * @param $ws
     * @return bool
     */
    private function getStampFileByState($state, $ws)
    {
        $stamps = Stamp::all()
            ->groupBy('state')
            ->map(function ($item) {
                return $item[0];
            })->toArray();

        if (array_key_exists($state, $stamps)) {
            return $ws ? $stamps[$state]['ws_file_name'] : $stamps[$state]['stamp_file_name'];
        }

        return false;
    }

    /**
     * Constructing data for pdf preview.
     *
     * @param $project
     * @param $template
     * @param $data
     * @return array
     * @throws \Throwable
     */
    private function constructJsonResponseData($project, $data, $template = 'print.lookup.preview.pdf_preview')
    {
        $stamp = $this->getStampFileByState($project->state, false);
        $stampWithoutSignature = $this->getStampFileByState($project->state, true);
        $view  = view($template, [
            'project' => $project,
            'atc'     => $data,
            'user'    => Auth::user(),
            'stamp'   => $stamp,
            'stampWithoutSignature' => $stampWithoutSignature
        ])->render();

        return [
            'view'                  => $view,
            'projectId'             => $project->id,
            'lookupData'            => $data,
            'stamp'                 => $stamp,
            'stampWithoutSignature' => $stampWithoutSignature
        ];
    }

    /**
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws Exception
     */
    public function showDocuments($id)
    {
        $authUser = Auth::user();
        $states = CountryState::getStates('US');

        Arr::forget($states, ['AA', 'AE', 'AP', 'PR', 'GU', 'UM', 'VI']);

        $project  = Project::with([
            'user',
            'audits' => function($query) {
                return $query->with('user');
            },
            'iebcMeta' => function($query) {
                return $query->with([
                    'audits' => function($query) {
                        return $query->with('user');
                    }
                ]);
            },
            'documents' => function ($query) {
                return $query
                    ->where('visibility', true)
                    ->with(['user', 'rType']);
            },
            'comments' => function ($query) {
                return $query->with(['user', 'commentDocuments']);
            },
            'projectAddons.audits.user',
            'projectShippingProfile.shippingProfile',
        ])
        ->where('id', $id)
        ->where('type', ProjectType::IEBC)
        ->firstOrFail();
        $documentTypes = ProjectDocumentType::getSelectSupportedValues(ProjectType::IEBC, $project->status);
        $projectAudits = $project->audits;
        $projectMetaAudits = $project->iebcMeta->audits;
        $audits = $projectAudits->merge($projectMetaAudits)->sortBy('created_at');

        if(count($project->projectAddons) === 1) {
            $ahjRequirements = $project->projectAddons[0]->audits;
            $audits = $ahjRequirements->merge($audits)->sortBy('created_at');
        }

        $shipmentProfiles = $authUser->shippingProfiles()->getRegulars()->get();

        $serviceOffer = ProjectAddon::where([
            ['project_id',  '=', $id],
            ['type', '=', ProjectAddonType::AHJ_SPECIAL_REQUIREMENTS]
        ])->get();

        if ($authUser->isUnderCompany()) {
            $shipmentProfiles = ShippingProfile::parentAndCurrentUser($authUser->parent_account_id)
                ->getRegulars()
                ->get();
        }

        if ($authUser->hasAssociatedUsers()) {
            $childUserIds = $authUser->associatedUsers()->pluck('id');
            $shipmentProfiles = ShippingProfile::childAndCurrentUser($childUserIds)
                ->getRegulars()
                ->get();
        }

        if ($authUser->hasRole(UserType::PZSE_ENGINEER)) {
            $projectAhjData = $project->projectAhjData;
            $lookupData     = $this->lookupEngineerValues($id);

            # return no_atc_data in case of returning warning instead of value inside lookupData array
            if (!((isset($lookupData['snow']['data']['data']['value']) && $lookupData['snow']['data']['data']['value'] !== null) || is_array($lookupData['snow']) || (isset($lookupData['snow']) && $lookupData['snow'] !== null))) {
                $lookupData['snow'] = MeteoEnum::NO_ATC_DATA;
            }
            if (!((isset($lookupData['wind']['data']['data']['value']) && $lookupData['wind']['data']['data']['value'] !== null) || is_array($lookupData['wind']) || (isset($lookupData['wind']) && $lookupData['wind'] !== null))) {
                $lookupData['wind'] = MeteoEnum::NO_ATC_DATA;
            }

            # checking if exists wind or snow data in separate project_ahj_data table
            if ($projectAhjData !== null && $projectAhjData->wind !== null && $projectAhjData->wind != 'SC' && $projectAhjData->wind != 'ATC') {
                $lookupData['wind'] = $projectAhjData->wind;
            }
            if ($projectAhjData !== null && $projectAhjData->snow !== null && $projectAhjData->snow != 'SC' && $projectAhjData->snow != 'ATC') {
                $lookupData['snow'] = $projectAhjData->snow;
            }

        } else {
            $lookupData = $this->lookup($id);
        }

        $roof_slope = $project->iebcMeta->roof_slope;

        return view('projects.iebc.show', [
            'project'          => $project,
            'audits'           => $audits,
            'documentTypes'    => $documentTypes,
            'shipmentProfiles' => $shipmentProfiles,
            'states'           => $states,
            'atc'              => $lookupData,
            'roof_slope'       => $roof_slope,
            'service_offers' => $serviceOffer
        ]);
    }

    /**
     * Uploading document with additional notes.
     *
     * @param ProjectDocumentsRequest $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function storeDocuments(ProjectDocumentsRequest $request, $id)
    {
        DB::beginTransaction();

        try {
            $authUser = Auth::user();
            $project = Project::find($id);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            $documentType = ProjectDocumentType::find($request->document_type);

            if (!$documentType) {
                DB::rollback();

                return response()->json([
                    'message' => 'Document type not found.'
                ], 404);
            }

            if ($documentType->id === \App\Enums\ProjectDocumentType::PLAN_CHECK_COMMENTS) {
                $project->update(['status' => ProjectStatus::PLAN_CHECK_COMMENTS]);
            }

            if (!$authUser->hasRole(UserType::PZSE_ENGINEER)
                && $documentType->id === \App\Enums\ProjectDocumentType::PLANS) {
                if($project->stamp_plans_request_count >= 5) {
                    (new PayServiceAction($authUser, ServiceTypes::ADDITIONAL_RESTAMP, $project))->execute();
                }

                $project->increment('stamp_plans_request_count', 1);
            }

            if ($documentType->id === \App\Enums\ProjectDocumentType::PLAN_CHECK_COMMENTS) {
                $project->update(['status' => ProjectStatus::PLAN_CHECK_COMMENTS]);
            }

            $project->status_display_text = ProjectStatus::getDescription($project->status);

            $filePath = "active/{$id}/{$documentType->save_path}";
            $notes = $request->additional_notes;
            $dispatcher = ProjectComments::getEventDispatcher();

            ProjectComments::unsetEventDispatcher();

            $projectComment = ProjectComments::create([
                'project_id'   => $id,
                'commented_by' => Auth::id(),
                'notes'        => $notes
            ]);

            ProjectComments::setEventDispatcher($dispatcher);

            $files = [];

            foreach ($request->file('document') as $document) {
                $documentName = $document->getClientOriginalName();

                $document->storeAs($filePath, $documentName);

                $projectDocument = ProjectDocuments::create([
                    'project_id' => $id,
                    'uploaded_by' => Auth::id(),
                    'file_name' => $documentName,
                    'type' => $documentType->id
                ]);
                $documentId = $projectDocument->id;

                $projectComment->commentDocuments()->attach($projectDocument->id);
                $commentId = $projectComment->id;

                $files[] = [
                    'project_id' => $id,
                    'documentType' => $documentType->name,
                    'file_name' => $documentName,
                    'downloadUrl' => route('projects.documents.download', [$id, $documentId]),
                    'pivot' => [
                        'project_document_id' => $documentId,
                        'project_comment_id' => $commentId,
                    ]
                ];
            }

            if ($documentType->id === \App\Enums\ProjectDocumentType::PLAN_CHECK_COMMENTS) {
                event(new PlanCheckFileAdded($projectDocument));
            } elseif (Auth::user()->hasAnyRole(UserType::STANDARD_USER, UserType::PRO_CLIENT)) {
                event(new ClientUploadedDocument($project));
            } elseif (Auth::user()->hasAnyRole([UserType::PZSE_ADMIN, UserType::PZSE_COORDINATOR, UserType::PZSE_ENGINEER])) {
                event(new PzseUploadedDocument($project));
            }

            DB::commit();

            return response()->json([
                'message' => 'Documents has been uploaded.',
                'data' => [
                    'status' => DocumentStatus::getDescription(DocumentStatus::UPLOADED),
                    'csrfToken' => csrf_token(),
                    'project' => $project,
                    'activity' => view('projects.iebc.partials.documents.activity-log', [
                        'project' => $project
                    ])->render(),
                    'project_documents' => view('projects.iebc.partials.documents.project-documents-table', [
                        'project' => $project
                    ])->render()
                ]
            ]);
        } catch (InsufficientBalanceException $exception) {
            DB::rollBack();

            return response()->json([
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
//                'message' => $exception->getMessage(),
                'message' => 'Something went wrong while saving document.'
            ], 500);
        }
    }

    /**
     * Downloading file.
     *
     * @param $projectId
     * @param $documentId
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     * @throws Exception
     */
    public function downloadDocument($projectId, $documentId)
    {
        try {
            $document     = ProjectDocuments::with('project')->findOrFail($documentId);
            $documentType = ProjectDocumentType::findOrFail($document->type);
            $projectType  = ProjectType::fromValue(ProjectType::IEBC)->key;

            $path = FilePathHelper::check(
                $projectId,
                $documentType->save_path,
                $document->file_name,
                $projectType
            );

            if (!auth()->user()->hasAnyRole([UserType::STANDARD_USER, UserType::PRO_CLIENT])) {
                $bundledDocument = ProjectDocuments::with('project')
                    ->where('visibility', false)
                    ->where('bundle_id', $document->bundle_id)
                    ->first();

                if ($bundledDocument) {
                    $bundledDocumentPath = FilePathHelper::check(
                        $projectId,
                        $documentType->save_path,
                        $bundledDocument->file_name,
                        $projectType
                    );
                }
            }

            if (isset($bundledDocument) && isset($bundledDocumentPath) && $bundledDocumentPath) {
                $zip = new ZipArchive();
                $fileName = uniqid() . '.zip';

                if ($zip->open(public_path($fileName), ZipArchive::CREATE) === TRUE) {
                    $zip->addFile($path, $document->file_name);
                    $zip->addFile($bundledDocumentPath, $bundledDocument->file_name);
                    $zip->close();
                }

                return response()
                    ->download(public_path($fileName))
                    ->deleteFileAfterSend(true);
            }

            return response()->download($path);
        } catch (Exception $exception) {
           throw $exception;
        }
    }

    /**
     * Adding a comment to project.
     *
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function addComment(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $comment = $request->comment;
            $project = Project::find($id);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            $projectComment = ProjectComments::create([
                'project_id'   => $project->id,
                'commented_by' => Auth::id(),
                'notes'        => $comment
            ]);

            if ((int) $request->comment_type === 1) {
                $project->update(['status' => ProjectStatus::PLAN_CHECK_COMMENTS]);
                event(new PlanCheckCommentAdded($project));
            }
            elseif (Auth::user()->hasAnyRole(UserType::STANDARD_USER, UserType::PRO_CLIENT)) {
                event(new ProjectCommented($project));
            }
            elseif (Auth::user()->hasAnyRole([UserType::PZSE_ADMIN, UserType::PZSE_COORDINATOR, UserType::PZSE_ENGINEER])) {
                event(new PzseUserCommentedProject($project));
            }

            $project->status_display_text = ProjectStatus::getDescription($project->status);

            DB::commit();

            return response()->json([
                'message' => 'Comment has been added.',
                'data' => [
                    'project' => $project,
                    'activity' => view('projects.iebc.partials.documents.activity-log', [
                        'project' => $project
                    ])->render(),
                    'project_documents' => view('projects.iebc.partials.documents.project-documents-table', [
                        'project' => $project
                    ])->render()
                ]
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while adding a comment.'
            ], 400);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit($id)
    {
        $authUser = Auth::user();
        $project  = Project::with('iebcMeta')->findOrFail($id);

        if ($project->status == ProjectStatus::COMPLETED) {
            abort(404);
        }

        if ($project->user_id != $authUser->id && !$authUser->hasPermissionTo(UserPermissionsEnum::EDIT_ASSOC_USERS_PROJECTS)) {
            abort(403);
        }

        $buildingDescriptions               = BuildingDescription::orderBy('order')->get();
        $surroundingTerrains                = SurroundingTerrain::all();
        $existingRoofLoads                  = ExistingRoofLoad::all();
        $canSeeRiskCatAndSurroundingTerrain = $authUser->hasAnyRole(UserType::PZSE_ENGINEER, UserType::PZSE_ADMIN);

        return view('projects.iebc.edit', [
            'states'                             => $this->states,
            'project'                            => $project,
            'buildingDescriptions'               => $buildingDescriptions,
            'surroundingTerrains'                => $surroundingTerrains,
            'existingRoofLoads'                  => $existingRoofLoads,
            'canSeeRiskCatAndSurroundingTerrain' => $canSeeRiskCatAndSurroundingTerrain
        ]);
    }

    /**
     * Requesting wet stamp from project index page.
     *
     * @param WetStampRequest $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeWetStampRequest(WetStampRequest $request, $projectId)
    {
        DB::beginTransaction();

        try {
            $saveShipmentProfile = $request->save_shipping_profile;
            $project = Project::find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            if ((int) $saveShipmentProfile === ShipmentProfileStatus::SAVE_REQUESTED ) {
                $request->merge(['regular' => ShipmentProfileSaveType::REGULAR]);
            }

            # Creating shipping profile for user.
            $shippingProfile = Auth::user()->shippingProfiles()
                ->create($request->all());

            # Attaching that shipment profile to project.
            $project->projectShippingProfile()
                ->create([
                    'project_id'              => $project->id,
                    'shipping_information_id' => $shippingProfile->id
                ]);

            # Updating project to ws requested
            $project->iebcMeta()
                ->firstOrFail()
                ->update([
                    'wet_sign_requested' => WetSign::REQUESTED
                ]);

            # Credit deduction
            $authUser = Auth::user();
            if (!$authUser->hasRole(UserType::PZSE_ENGINEER)) {
                (new PayServiceAction($authUser, ServiceTypes::WET_STAMP, $project))->execute();

                ProjectIebcMeta::where('project_id', $project->id)
                    ->firstOrFail()
                    ->update(['wet_sign_requested' => WetSign::REQUESTED]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Wet stamp successfully requested.',
                'type'    => 'save',
                'data'    => [
                    'update_route' => route('projects.wet-stamp-request.update', ['project' => $project->id, 'ws' => $shippingProfile->id]),
                    'cancel_route' => route('projects.wet-stamp-request.cancel', ['project' => $project->id, 'ws' => $shippingProfile->id]),
                ]
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while requesting wet stamp.'
            ], 400);
        }
    }

    /**
     * Updating wet stamp from project page.
     *
     * @param WetStampRequest $request
     * @param $projectId
     * @param $shipmentProfileId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateWetStampRequest(WetStampRequest $request, $projectId, $shipmentProfileId)
    {
        DB::beginTransaction();

        try {
            $project =  Project::find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            $shipmentProfile = ShippingProfile::findOrFail($shipmentProfileId);
            $shipmentProfile->update($request->all());

            DB::commit();

            return response()->json([
                'message' => 'Wet stamp shipment info successfully updated.',
                'type'    => 'update'
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while requesting wet stamp.'
            ], 400);
        }
    }

    /**
     * Cancel wet stamp request from project page.
     *
     * @param $projectId
     * @param $shipmentProfileId
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelWetStampRequest($projectId, $shipmentProfileId)
    {
        DB::beginTransaction();

        try {
            $service = Service::where('slug', ServiceTypes::WET_STAMP)->first();
            $project = Project::find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            ProjectShippingInformation::where('shipping_information_id', $shipmentProfileId)
                ->firstOrFail()
                ->delete();

            $project->iebcMeta()
                ->firstOrFail()
                ->update(['wet_sign_requested' => WetSign::REQUESTED_CANCELLED]);

            # Refunding credit after canceling wet stamp request
            $authUser= Auth::user();
            if (!$authUser->hasRole(UserType::PZSE_ENGINEER)) {
                $deductFromUser = $authUser->isUnderCompany() ? $authUser->parent_account_id : $authUser->id;
            }

            if (!$authUser->hasRole(UserType::PZSE_ENGINEER)) {
                BalanceHelper::fillBalance($deductFromUser, $service->price);

                ProjectIebcMeta::where('project_id', $project->id)
                    ->firstOrFail()
                    ->update(['wet_sign_requested' => WetSign::REQUESTED_CANCELLED]);
            }

            DB::commit();

            return response()->json([
                'message'    => 'Wet stamp request canceled.',
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while canceling wet stamp.'
            ], 400);
        }
    }

    /**
     * Manual change of project status.
     *
     * @param StatusChangeRequest $request
     * @param $projectId
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function changeStatus(StatusChangeRequest $request, $projectId)
    {
        DB::beginTransaction();

        try {
            $project = Project::with('projectShippingProfile.shippingProfile')->find($projectId);

            if (!$project) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Project not found.'
                ], 404);
            }

            $project->update(['status' => $request->status]);

            $trackingInfo = [
                'tracking_number'  => $request->get('shipping_tracking_number'),
                'additional_notes' => $request->get('shipping_additional_notes')
            ];

            $project->projectShippingProfile()->update($trackingInfo);

            $project->refresh();

            DB::commit();

            return response()->json([
                'message' => 'Project status successfully changed.',
                'data'    => [
                    'status' => [
                        'code'        => $request->status,
                        'description' => ProjectStatus::getDescription($request->status)
                    ],
                    'tracking_number'       => $request->shipping_tracking_number,
                    'additional_info'       => $request->shipping_additional_notes,
                    'tracking_info_partial' => view('projects.iebc.partials.wet-stamp.tracking-information', [
                        'project' => $project
                    ])->render(),
                    'readonly_shipping_info_partial' => view('projects.iebc.partials.wet-stamp.readonly-shipping-information', [
                        'pShipmentProfile' => $project->projectShippingProfile->shippingProfile,
                        'project'          => $project
                    ])->render(),
                ]
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while updating project status.'
            ], 400);
        }
    }

    /**
     * Checking if wet stamp is required based on chosen state.
     *
     * @param Request $request
     * @return mixed
     */
    public function checkIfStampRequired(Request $request)
    {
        $stamp = Stamp::where('state', $request->state)->first();

        return response()->json([
            'stamp' => $stamp
        ]);
    }

    /**
     * Downloading pdf.
     *
     * @param DownloadPdfRequest $request
     * @return string
     * @throws \setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException
     * @throws \setasign\Fpdi\PdfParser\Filter\FilterException
     * @throws \setasign\Fpdi\PdfParser\PdfParserException
     * @throws \setasign\Fpdi\PdfParser\Type\PdfTypeException
     * @throws \setasign\Fpdi\PdfReader\PdfReaderException
     */
    public function downloadPdf(DownloadPdfRequest $request)
    {
        // Deleting all files in tmp directory.
        File::cleanDirectory(storage_path('tmppdf'));

        $projectId  = $request->project_id;
        $lookUpData = json_decode($request->lookup_data, true);
        $project    = Project::with([
            'iebcMeta' => function ($query) {
                $query->with([
                    'buildingDescription',
                    'surroundingTerrain',
                    'roofType',
                ]);
            }])->find($projectId);

        if (!$project) {
            abort(404);
        }

        $stamp = $this->getStampFileByState($project->state, $project->wet_sign_requested);

        $view = env('PDF_DEV_ENV') ? 'print.lookup.dev_index' : 'print.lookup.index';
        $viewData = [
            'project' => $project,
            'atc'     => $lookUpData,
            'user'    => Auth::user(),
            'stamp'   => $stamp
        ];
        $tmpPath = storage_path('tmppdf/' . uniqid() . '.pdf');

        # saving tmp PDF.
        $this->savePdf($tmpPath, $view, $viewData);

        # Loading tmp pdf in tcpdf for adding digital signature.
        $fPdi        = new Fpdi('P', 'in', [8.5, 11]);
        $pagesCount  = $fPdi->setSourceFile($tmpPath);
        $certificate = 'file://' . storage_path('dsign/certificate.crt');
        $privateKey  = 'file://' . storage_path('dsign/keyfile-decrypted.key');

        for ($i = 1; $i <= $pagesCount; $i++) {
            $fPdi->setPrintHeader(false);
            $fPdi->setPrintHeader(false);
            $fPdi->AddPage();
            $page = $fPdi->importPage($i);
            $fPdi->useTemplate($page);
            $fPdi->setSignature($certificate, $privateKey);
        }

        return $fPdi->output('PZSE-IEBC-Letter-' . now()->format('Y-m-d') . '.pdf', 'D');
    }

    /**
     * Saving PDF.
     * @param $path
     * @param $view
     * @param array $viewData
     */
    private function savePdf($path, $view, $viewData = [])
    {
        $pdf  = App::make('snappy.pdf.wrapper');
        $pdf->loadView($view, $viewData)->setPaper('Letter')
            ->setOption('enable-smart-shrinking', env('PDF_DEV_ENV'))
            ->setOption('dpi', 300)
            ->setOption('margin-bottom', '0.25in')
            ->setOption('margin-left', '0.5in')
            ->setOption('margin-right', '0.5in')
            ->setOption('margin-top', '0.25in');
        $pdf->save($path);
    }

    /**
     * Do a Lookup and return found data.
     *
     * @param $projectId
     * @return array|string
     * @throws Exception
     */
    private function lookup($projectId)
    {
        $project = Project::with([
            'user',
            'iebcMeta' => function ($query) {
                $query->with([
                    'buildingDescription',
                    'surroundingTerrain',
                    'roofType',
                ]);
            }])->findOrFail($projectId);

        return (new Lookup($project))->doLookup();
    }

    /**
     * Do a Lookup and return found data.
     *
     * @param $projectId
     * @return array|string
     * @throws Exception
     */
    private function lookupEngineerValues($projectId)
    {
        $project = Project::with([
            'user',
            'iebcMeta' => function ($query) {
                $query->with([
                    'buildingDescription',
                    'surroundingTerrain',
                    'roofType',
                ]);
            }])->findOrFail($projectId);

        return (new Lookup($project))->doLookupEngineerValues();
    }

    /**
     * Manual sending document uploaded notification
     *
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function documentUploadedNotification($id)
    {
        $project         = Project::findOrFail($id);
        event(new DocumentUploaded($project));
        return response()->json([
            'message' => 'Notification Sent Successfully.',
            'data'    => '2',
        ]);
    }

    /**
     * Validating request and returning error message based on criteria.
     *
     * @param $data
     * @param $fields
     * @return \Illuminate\Contracts\Validation\Validator
     */
    private function validateRequest(array $data, array $fields)
    {
        $buildingDescriptionId = $data['building_description_id'];
        $surroundingTerrainId  = $data['surrounding_terrain_id'];
        $existingRoofLoadId    = $data['existing_roof_type_id'];
        $messages              = [
            'building_description_id.should_refer' => $this->getErrorMessage('risk_cat', ['[riskCat]'], [$fields['risk_category_internal_name']]),
            'surrounding_terrain_id.should_refer'  => $this->getErrorMessage('exposure', ['[exposure]'], [$fields['exposure_category']]),
            'existing_roof_type_id.should_refer'   => $this->getErrorMessage('other'),
        ];

        $validator = Validator::make(
            [
                'building_description_id' => $buildingDescriptionId,
                'surrounding_terrain_id'  => $surroundingTerrainId,
                'existing_roof_type_id'   => $existingRoofLoadId
            ],
            [
                'building_description_id' => 'should_refer:' . BuildingDescription::class,
                'surrounding_terrain_id'  => 'should_refer:' . SurroundingTerrain::class,
                'existing_roof_type_id'   => 'should_refer:' . ExistingRoofLoad::class
            ],
            $messages
        );

        return $validator;
    }

    /**
     * Getting error message based on type.
     *
     * @param string $type
     * @param $dataToReplace
     * @param $replaceWith
     * @return string
     */
    private function getErrorMessage(string $type, $dataToReplace = [], $replaceWith = []): string
    {
        $errorMessage = config('meteo.error_messages.' . $type);

        if (!empty($dataToReplace) && !empty($replaceWith)) {
            $errorMessage = str_replace($dataToReplace, $replaceWith, $errorMessage);
            return $errorMessage;
        }

        return $errorMessage;
    }
}
